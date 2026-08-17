# Flutter + embedding are kept by the Flutter Gradle plugin's consumer rules.
# Firebase Messaging uses reflection for its services; keep it explicitly so
# minify does not strip the push receiver.
-keep class com.google.firebase.** { *; }
-keep class com.google.android.gms.** { *; }
-dontwarn com.google.firebase.**
