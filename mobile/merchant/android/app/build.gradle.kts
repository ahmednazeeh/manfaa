import java.util.Properties
import java.io.FileInputStream

plugins {
    id("com.android.application")
    // Parses android/app/google-services.json — a PLACEHOLDER until the
    // owner registers mv.manfaa.merchant in Firebase console project
    // manfaa-6e1b4 (see lib/firebase_options.dart for the swap procedure).
    // The placeholder is well-formed, so the build works today and push
    // lights up when the real file replaces it — zero code changes.
    id("com.google.gms.google-services")
    // The Flutter Gradle Plugin must be applied after the Android and Kotlin Gradle plugins.
    id("dev.flutter.flutter-gradle-plugin")
}

// Release signing lives in android/key.properties, which is gitignored and
// points at a keystore kept OUTSIDE the repo (a NEW merchant keystore in MR6
// — deliberately separate from the customer key). When it is absent (a fresh
// checkout, or CI before secrets are wired), the release build falls back to
// debug signing so `flutter run --release` still works — it just isn't
// distributable.
val keystoreProperties = Properties()
val keystorePropertiesFile = rootProject.file("key.properties")
val hasReleaseSigning = keystorePropertiesFile.exists()
if (hasReleaseSigning) {
    keystoreProperties.load(FileInputStream(keystorePropertiesFile))
}

android {
    namespace = "mv.manfaa.merchant"
    // flutter_secure_storage requires SDK 37; android-37.0 is installed.
    compileSdk = 37
    ndkVersion = flutter.ndkVersion

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }

    defaultConfig {
        applicationId = "mv.manfaa.merchant"
        minSdk = flutter.minSdkVersion
        targetSdk = flutter.targetSdkVersion
        versionCode = flutter.versionCode
        versionName = flutter.versionName
    }

    signingConfigs {
        if (hasReleaseSigning) {
            create("release") {
                storeFile = file(keystoreProperties["storeFile"] as String)
                storePassword = keystoreProperties["storePassword"] as String
                keyAlias = keystoreProperties["keyAlias"] as String
                keyPassword = keystoreProperties["keyPassword"] as String
            }
        }
    }

    buildTypes {
        release {
            signingConfig = if (hasReleaseSigning) {
                signingConfigs.getByName("release")
            } else {
                signingConfigs.getByName("debug")
            }
            isMinifyEnabled = true
            isShrinkResources = true
            proguardFiles(
                getDefaultProguardFile("proguard-android-optimize.txt"),
                "proguard-rules.pro",
            )
        }
    }
}

kotlin {
    compilerOptions {
        jvmTarget = org.jetbrains.kotlin.gradle.dsl.JvmTarget.JVM_17
    }
}

flutter {
    source = "../.."
}
