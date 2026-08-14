'use client';

export function Footer() {
  const currentYear = new Date().getFullYear();

  return (
    <footer className="footer">
      <div className="container">
        <div className="flex justify-center md:justify-start items-center py-5">
          <span className="flex gap-2 font-normal text-sm text-muted-foreground">
            {currentYear} &copy; Manfaa — merchant panel
          </span>
        </div>
      </div>
    </footer>
  );
}
