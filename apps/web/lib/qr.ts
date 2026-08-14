/**
 * Minimal QR encoder for the customer code — Version 1 (21x21), byte mode,
 * error-correction level L, mask 0. No dependencies: the payload is a short
 * 6-digit code, far under Version 1-L's 17-byte capacity, so one fixed
 * version keeps this tiny. Returns null for payloads that do not fit, and
 * the caller falls back to showing the code without a QR.
 */

const SIZE = 21;
const DATA_CODEWORDS = 19;
const EC_CODEWORDS = 7;
const MAX_BYTES = 17;

/** Format info for EC level L + mask 0 (ISO/IEC 18004), bit 0 leftmost. */
const FORMAT_BITS = '111011111000100';

// ---------------------------------------------------------------------------
// GF(256) arithmetic (polynomial 0x11d) for Reed-Solomon EC codewords
// ---------------------------------------------------------------------------

const GF_EXP = new Uint8Array(512);
const GF_LOG = new Uint8Array(256);
(() => {
  let x = 1;
  for (let i = 0; i < 255; i++) {
    GF_EXP[i] = x;
    GF_LOG[x] = i;
    x <<= 1;
    if (x & 0x100) {
      x ^= 0x11d;
    }
  }
  for (let i = 255; i < 512; i++) {
    GF_EXP[i] = GF_EXP[i - 255];
  }
})();

function gfMul(a: number, b: number): number {
  if (a === 0 || b === 0) {
    return 0;
  }
  return GF_EXP[GF_LOG[a] + GF_LOG[b]];
}

/**
 * Generator polynomial of degree `degree`: product of (x - a^i), built
 * lowest-power-first — poly[j] is the coefficient of x^j and the leading
 * coefficient poly[degree] is 1.
 */
function generatorPoly(degree: number): number[] {
  let poly = [1];
  for (let i = 0; i < degree; i++) {
    const next = new Array<number>(poly.length + 1).fill(0);
    for (let j = 0; j < poly.length; j++) {
      next[j] ^= gfMul(poly[j], GF_EXP[i]);
      next[j + 1] ^= poly[j];
    }
    poly = next;
  }
  return poly;
}

function reedSolomon(data: number[], degree: number): number[] {
  const gen = generatorPoly(degree);
  const remainder = new Array<number>(degree).fill(0);
  for (const byte of data) {
    const factor = byte ^ remainder[0];
    remainder.shift();
    remainder.push(0);
    for (let i = 0; i < degree; i++) {
      // remainder[0] tracks the highest power, so walk gen descending
      // (leading 1 excluded): gen[degree - 1 - i] is x^(degree-1-i).
      remainder[i] ^= gfMul(gen[degree - 1 - i], factor);
    }
  }
  return remainder;
}

// ---------------------------------------------------------------------------
// Codeword stream
// ---------------------------------------------------------------------------

function buildCodewords(text: string): number[] | null {
  const bytes = Array.from(new TextEncoder().encode(text));
  if (bytes.length > MAX_BYTES) {
    return null;
  }

  const bits: number[] = [];
  const push = (value: number, length: number) => {
    for (let i = length - 1; i >= 0; i--) {
      bits.push((value >> i) & 1);
    }
  };

  push(0b0100, 4); // byte mode
  push(bytes.length, 8); // character count (8 bits for versions 1-9)
  for (const byte of bytes) {
    push(byte, 8);
  }
  // Terminator (up to 4 zero bits), then pad to a byte boundary.
  push(0, Math.min(4, DATA_CODEWORDS * 8 - bits.length));
  while (bits.length % 8 !== 0) {
    bits.push(0);
  }

  const data: number[] = [];
  for (let i = 0; i < bits.length; i += 8) {
    let byte = 0;
    for (let j = 0; j < 8; j++) {
      byte = (byte << 1) | bits[i + j];
    }
    data.push(byte);
  }
  // Alternating pad codewords up to capacity.
  const pads = [0xec, 0x11];
  for (let i = 0; data.length < DATA_CODEWORDS; i++) {
    data.push(pads[i % 2]);
  }

  return [...data, ...reedSolomon(data, EC_CODEWORDS)];
}

// ---------------------------------------------------------------------------
// Matrix
// ---------------------------------------------------------------------------

/**
 * Encodes `text` as a Version 1-L QR matrix. `true` = dark module.
 * Returns null when the payload exceeds Version 1 capacity.
 */
export function encodeQr(text: string): boolean[][] | null {
  const codewords = buildCodewords(text);
  if (codewords === null) {
    return null;
  }

  const modules: boolean[][] = Array.from({ length: SIZE }, () =>
    new Array<boolean>(SIZE).fill(false),
  );
  const reserved: boolean[][] = Array.from({ length: SIZE }, () =>
    new Array<boolean>(SIZE).fill(false),
  );

  const set = (row: number, col: number, dark: boolean) => {
    modules[row][col] = dark;
    reserved[row][col] = true;
  };

  // Finder patterns with their separators, at three corners.
  const placeFinder = (top: number, left: number) => {
    for (let r = -1; r <= 7; r++) {
      for (let c = -1; c <= 7; c++) {
        const row = top + r;
        const col = left + c;
        if (row < 0 || row >= SIZE || col < 0 || col >= SIZE) {
          continue;
        }
        const inFinder = r >= 0 && r <= 6 && c >= 0 && c <= 6;
        const dark =
          inFinder &&
          (r === 0 ||
            r === 6 ||
            c === 0 ||
            c === 6 ||
            (r >= 2 && r <= 4 && c >= 2 && c <= 4));
        set(row, col, dark);
      }
    }
  };
  placeFinder(0, 0);
  placeFinder(0, SIZE - 7);
  placeFinder(SIZE - 7, 0);

  // Timing patterns.
  for (let i = 8; i < SIZE - 8; i++) {
    set(6, i, i % 2 === 0);
    set(i, 6, i % 2 === 0);
  }

  // Dark module.
  set(SIZE - 8, 8, true);

  // Format information, both copies (EC L, mask 0).
  for (let i = 0; i < 15; i++) {
    const bit = FORMAT_BITS[i] === '1';
    // Copy around the top-left finder.
    if (i < 6) {
      set(8, i, bit);
    } else if (i < 8) {
      set(8, i + 1, bit);
    } else if (i === 8) {
      set(7, 8, bit);
    } else {
      set(14 - i, 8, bit);
    }
    // Copy split between bottom-left and top-right.
    if (i < 7) {
      set(SIZE - 1 - i, 8, bit);
    } else {
      set(8, SIZE - 15 + i, bit);
    }
  }

  // Data + EC bits, zigzag from the bottom-right, mask 0.
  const bits: number[] = [];
  for (const codeword of codewords) {
    for (let i = 7; i >= 0; i--) {
      bits.push((codeword >> i) & 1);
    }
  }

  let bitIndex = 0;
  let upward = true;
  for (let right = SIZE - 1; right >= 1; right -= 2) {
    if (right === 6) {
      right = 5; // the vertical timing column is skipped entirely
    }
    for (let step = 0; step < SIZE; step++) {
      const row = upward ? SIZE - 1 - step : step;
      for (const col of [right, right - 1]) {
        if (reserved[row][col]) {
          continue;
        }
        const bit = bitIndex < bits.length ? bits[bitIndex] === 1 : false;
        bitIndex++;
        const masked = (row + col) % 2 === 0 ? !bit : bit;
        modules[row][col] = masked;
      }
    }
    upward = !upward;
  }

  return modules;
}

/**
 * SVG path data (`d` attribute) for the dark modules, one unit per module.
 * Render inside `viewBox="0 0 21 21"` with `fill-rule` left at default.
 */
export function qrPath(modules: boolean[][]): string {
  const parts: string[] = [];
  for (let row = 0; row < modules.length; row++) {
    for (let col = 0; col < modules[row].length; col++) {
      if (modules[row][col]) {
        parts.push(`M${col} ${row}h1v1h-1z`);
      }
    }
  }
  return parts.join('');
}

export const QR_SIZE = SIZE;
