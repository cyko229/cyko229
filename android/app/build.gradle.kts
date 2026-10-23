plugins {
    id("com.android.application")
    id("org.jetbrains.kotlin.android")
}

// FCM is optional. The Google Services plugin hard-fails when google-services.json
// is missing, so we only apply it once you have dropped your Firebase config in.
// Without it the app still builds and runs — on the WebSocket channel alone.
val hasFirebaseConfig = file("google-services.json").exists()
if (hasFirebaseConfig) {
    apply(plugin = "com.google.gms.google-services")
} else {
    logger.lifecycle("[RemoteWake] no app/google-services.json — building without FCM push.")
}

android {
    namespace = "com.cyko.remotewake"
    compileSdk = 34

    defaultConfig {
        applicationId = "com.cyko.remotewake"
        minSdk = 27            // setShowWhenLocked / setTurnScreenOn need API 27+
        targetSdk = 34
        versionCode = 1
        versionName = "1.0"
    }

    buildTypes {
        release {
            isMinifyEnabled = false
            proguardFiles(getDefaultProguardFile("proguard-android-optimize.txt"), "proguard-rules.pro")
        }
    }

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }
    kotlinOptions {
        jvmTarget = "17"
    }
    buildFeatures {
        viewBinding = true
    }
}

dependencies {
    implementation("androidx.core:core-ktx:1.13.1")
    implementation("androidx.appcompat:appcompat:1.7.0")
    implementation("com.google.android.material:material:1.12.0")
    implementation("androidx.lifecycle:lifecycle-service:2.8.4")

    // OkHttp gives us a resilient WebSocket client with automatic ping/pong.
    implementation("com.squareup.okhttp3:okhttp:4.12.0")

    // Firebase Cloud Messaging: the only channel that still wakes the phone in Doze.
    // The BoM keeps the Firebase artifacts on matching versions.
    implementation(platform("com.google.firebase:firebase-bom:33.1.2"))
    implementation("com.google.firebase:firebase-messaging")
}
