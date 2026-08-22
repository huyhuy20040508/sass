// Khai plugin cho cả project, chưa áp dụng ở đây — module con tự apply.
plugins {
    alias(libs.plugins.android.application) apply false
    alias(libs.plugins.kotlin.android) apply false
    alias(libs.plugins.kotlin.compose) apply false
}
