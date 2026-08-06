import java.io.File
import java.io.FileInputStream
import java.util.Properties

plugins {
    id("com.android.application")
    // The Flutter Gradle Plugin must be applied after the Android and Kotlin Gradle plugins.
    id("dev.flutter.flutter-gradle-plugin")
}

/*
 * Le trousseau de signature, s'il est présent.
 *
 * HORS DU DÉPÔT, TOUJOURS : versionné, il permettrait à quiconque lit ce code
 * de publier une application se faisant passer pour Preuve.
 */
val fichierDeSignature: File = rootProject.file("key.properties")
val proprietesDeSignature: Properties? = if (fichierDeSignature.exists()) {
    val lues = Properties()
    FileInputStream(fichierDeSignature).use { flux -> lues.load(flux) }
    lues
} else {
    null
}

android {
    /*
     * LE MÊME QUE `applicationId`. Les laisser diverger produit un binaire qui
     * s'installe et ne démarre pas : le lanceur cherche `MainActivity` sous un
     * nom que le système ne résout pas. Le défaut ne se voit qu'en compilation
     * de publication, sur un appareil — jamais en débogage.
     */
    namespace = "ci.bookmi.preuve"
    compileSdk = flutter.compileSdkVersion
    ndkVersion = flutter.ndkVersion

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }

    defaultConfig {
        /*
         * L'IDENTIFIANT EST DÉFINITIF DÈS LA PREMIÈRE PUBLICATION. Il ne se
         * change plus jamais : une application publiée sous un autre
         * identifiant est une AUTRE application, et tous les installés sont
         * abandonnés.
         *
         * Il ne dérive donc PAS du domaine. `click.preuve.preuve_app` liait
         * l'application à `preuve.click` — un domaine encore en question, le
         * `.ci` restant à trancher — et un changement de domaine aurait figé
         * pour toujours un identifiant qui ment. Il porte l'ÉDITEUR (BookMi) et
         * le PRODUIT (Preuve), qui eux ne bougeront pas.
         */
        applicationId = "ci.bookmi.preuve"
        // You can update the following values to match your application needs.
        // For more information, see: https://flutter.dev/to/review-gradle-config.
        minSdk = flutter.minSdkVersion
        targetSdk = flutter.targetSdkVersion
        versionCode = flutter.versionCode
        versionName = flutter.versionName
    }

    signingConfigs {
        create("release") {
            if (proprietesDeSignature != null) {
                keyAlias = proprietesDeSignature.getProperty("keyAlias")
                keyPassword = proprietesDeSignature.getProperty("keyPassword")
                // RÉSOLU DEPUIS `android/`, PAS DEPUIS `android/app/` : `file()`
                // dans un module se résout relativement au module, et le
                // trousseau vit à côté de `key.properties`, un niveau au-dessus.
                storeFile = rootProject.file(proprietesDeSignature.getProperty("storeFile"))
                storePassword = proprietesDeSignature.getProperty("storePassword")
            }
        }
    }

    buildTypes {
        release {
            /*
             * SIGNÉ AVEC LA CLÉ DE PUBLICATION, ou pas signé du tout.
             *
             * Le modèle livré par `flutter create` signe la version de
             * publication avec la CLÉ DE DÉBOGAGE et laisse un TODO. Google
             * Play refuse un tel binaire ; et s'il passait, aucune mise à jour
             * ne pourrait plus jamais être publiée, la clé de débogage étant
             * régénérée sur chaque machine.
             *
             * On ne retombe donc PAS en silence sur la clé de débogage : sans
             * `key.properties`, le binaire sort NON SIGNÉ et le message dit
             * quoi faire. Un échec bruyant vaut mieux qu'un artefact
             * impubliable qu'on découvre au moment du dépôt.
             */
            signingConfig = if (proprietesDeSignature != null) {
                signingConfigs.getByName("release")
            } else {
                logger.warn(
                    "PREUVE : android/key.properties est absent. La version de " +
                        "publication NE SERA PAS SIGNÉE et Google Play la refusera.",
                )
                null
            }

            // Retire le code et les ressources inutilisés : sur un parc où le
            // stockage et la donnée sont comptés, chaque mégaoctet se paie.
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
