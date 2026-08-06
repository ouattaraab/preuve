# Flutter et ses greffons chargent du code par réflexion : R8 ne peut pas le
# voir, et le retirerait. Omettre ces règles produit une application qui
# compile et plante à l'ouverture — sur les appareils réels, jamais en débogage.
-keep class io.flutter.** { *; }
-keep class io.flutter.plugins.** { *; }

# ML Kit charge ses modèles par nom de classe.
-keep class com.google.mlkit.** { *; }
-keep class com.google.android.gms.internal.mlkit_** { *; }
-dontwarn com.google.mlkit.**

# Flutter référence Play Core pour les « composants différés », que Preuve
# n'utilise pas : la bibliothèque n'est donc pas au projet, et R8 refuse de
# terminer sur des classes absentes. On lui dit qu'elles le sont à dessein.
# Les garder ferait entrer une dépendance Google de plus pour du code mort.
-dontwarn com.google.android.play.core.**
-keep class io.flutter.embedding.engine.deferredcomponents.** { *; }
