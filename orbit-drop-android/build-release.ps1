# Builds a signed release APK for Myket.
# Usage (from this folder):  powershell -ExecutionPolicy Bypass -File .\build-release.ps1
# The keystore password is asked here and kept only in memory for this build.
$ErrorActionPreference = "Stop"
Set-Location $PSScriptRoot

$env:JAVA_HOME = "C:\Program Files\Eclipse Adoptium\jdk-21.0.13.9-hotspot"
$env:ORBIT_KEYSTORE = "D:\job\bazi\orbit-drop-release.jks"

$sec = Read-Host "Keystore password" -AsSecureString
$env:ORBIT_KS_PASS = [Runtime.InteropServices.Marshal]::PtrToStringAuto([Runtime.InteropServices.Marshal]::SecureStringToBSTR($sec))

try {
    Copy-Item ..\orbit-drop\index.html www\index.html -Force
    npx cap sync android
    Push-Location android
    .\gradlew.bat -I myket-mirror.gradle assembleRelease
    $code = $LASTEXITCODE
    Pop-Location
    if ($code -ne 0) { throw "Build failed (exit $code). If it says 'password was incorrect', run again and type the keystore password carefully." }
    $apk = "android\app\build\outputs\apk\release\app-release.apk"
    $sdk = ((Get-Content android\local.properties | Where-Object { $_ -like "sdk.dir=*" }) -replace "^sdk.dir=", "")
    $bt = Get-ChildItem (Join-Path $sdk "build-tools") -Directory -ErrorAction SilentlyContinue | Sort-Object Name -Descending | Select-Object -First 1
    if ($bt) { cmd /c "`"$($bt.FullName)\apksigner.bat`" verify --print-certs `"$apk`"" | Select-String "Signer #1 certificate (DN|SHA-256)" } else { Write-Host "(apksigner not found; signature check skipped)" }
    Write-Host "`nSigned APK: $PSScriptRoot\$apk" -ForegroundColor Green
}
finally {
    Remove-Item Env:ORBIT_KS_PASS -ErrorAction SilentlyContinue
}
