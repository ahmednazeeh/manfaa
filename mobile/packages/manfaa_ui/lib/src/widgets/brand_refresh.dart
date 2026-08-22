import 'package:flutter/material.dart';

/// Runs [onResume] whenever the app comes back to the foreground.
///
/// Phones are pocketed, not restarted. Anything the app read once at launch
/// — the platform's brand marks, the server's feature flags — is otherwise
/// as old as the process, and a superadmin's change never arrives until
/// something kills it.
///
/// The callback is expected to be cheap and self-limiting: the brand cache
/// does nothing unless its 24 hours are up, and a config re-read is a
/// conditional GET that usually answers 304.
class OnAppResume extends StatefulWidget {
  const OnAppResume({super.key, required this.onResume, required this.child});

  final VoidCallback onResume;
  final Widget child;

  @override
  State<OnAppResume> createState() => _OnAppResumeState();
}

class _OnAppResumeState extends State<OnAppResume> {
  AppLifecycleListener? _listener;

  @override
  void initState() {
    super.initState();
    _listener = AppLifecycleListener(onResume: () => widget.onResume());
  }

  @override
  void dispose() {
    _listener?.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => widget.child;
}
