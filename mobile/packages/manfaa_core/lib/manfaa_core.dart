/// Shared client core for the Manfaa mobile apps.
///
/// Everything here is app-agnostic on purpose: the merchant app imports this
/// same package, which is why it exists as a package at all. The transport
/// contract it implements is docs/mobile-api-guide.md.
library;

export 'src/api_base.dart';
export 'src/api_client.dart';
export 'src/config_gate.dart';
export 'src/credit_queue.dart';
export 'src/errors.dart';
export 'src/http/interceptors.dart';
export 'src/merchant_api.dart';
export 'src/merchant_models.dart';
export 'src/models.dart';
export 'src/money.dart';
export 'src/session.dart';
