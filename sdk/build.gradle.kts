plugins {
    alias(libs.plugins.android.library)
    alias(libs.plugins.kotlin.android)
    `maven-publish`
    signing
}

val sdkVersion = providers.gradleProperty("VERSION_NAME").get()

android {
    namespace = "com.playndgain.sdk"
    compileSdk = 35

    defaultConfig {
        minSdk = 23
        consumerProguardFiles("consumer-rules.pro")
        buildConfigField("String", "SDK_VERSION", "\"$sdkVersion\"")
    }

    buildFeatures { buildConfig = true }

    buildTypes {
        release { isMinifyEnabled = false }
    }

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }

    testOptions { unitTests.isReturnDefaultValues = true }

    publishing {
        singleVariant("release") { withSourcesJar() }
    }
}

kotlin {
    // Bytecode targets Java 17 (compileOptions above); any JDK 17+ can build it.
    compilerOptions { jvmTarget.set(org.jetbrains.kotlin.gradle.dsl.JvmTarget.JVM_17) }
    explicitApi()
}

dependencies {
    implementation(libs.androidx.recyclerview)

    testImplementation(libs.junit)
    testImplementation(libs.json)
}

// Configured, never run here: publishing and signing keys are the owner's.
publishing {
    publications {
        register<MavenPublication>("release") {
            groupId = providers.gradleProperty("GROUP").get()
            artifactId = providers.gradleProperty("POM_ARTIFACT_ID").get()
            version = sdkVersion
            afterEvaluate { from(components["release"]) }
            pom {
                name.set(providers.gradleProperty("POM_NAME"))
                description.set(providers.gradleProperty("POM_DESCRIPTION"))
                url.set("https://playndgain.com/docs/mobile-sdk")
                licenses {
                    license {
                        name.set("Apache License 2.0")
                        url.set("https://www.apache.org/licenses/LICENSE-2.0")
                    }
                }
            }
        }
    }
}

signing {
    val key = System.getenv("SIGNING_IN_MEMORY_KEY")
    if (key != null) {
        useInMemoryPgpKeys(key, System.getenv("SIGNING_IN_MEMORY_KEY_PASSWORD"))
        sign(publishing.publications)
    }
}
