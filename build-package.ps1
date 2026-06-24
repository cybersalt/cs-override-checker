# Build script for cs-template-integrity
# Produces a single installable package at the repo root:
#   pkg_cstemplateintegrity_v{version}_{YYYYMMDD}_{HHMM}.zip
# which wraps both the component and the webservices plugin. Install
# this one zip; Joomla unpacks it and installs both child extensions.
# The package's script.php auto-enables the webservices plugin on
# postflight so the API routes are live immediately.
#
# Child extension zips use stable (non-timestamped) names inside the
# package so the package manifest can reference them reliably.
#
# Zips are built with 7-Zip because Windows' Compress-Archive omits
# directory entries, which breaks Joomla's installer.

param(
    [string]$Version = ""
)

$ErrorActionPreference = "Stop"

$scriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$sevenZip  = "C:\Program Files\7-Zip\7z.exe"

if (-not (Test-Path $sevenZip)) {
    throw "7-Zip not found at $sevenZip. Install 7-Zip or update the path in build-package.ps1."
}

# UTF-8 BOM check across every PHP / XML / INI in the source tree.
# A BOM in front of a `<?php` opener emits raw bytes before the PHP
# parser starts, which breaks declare(strict_types=1) (Joomla Brain
# warns about this). Set-Content -Encoding utf8 in Windows PS 5.1
# adds BOM by default — one slip during the v1.0.2 build made the
# whole release unusable. Fail the build here rather than discover
# at install.
$bomFiles = @()
$packagesPath = Join-Path $scriptDir "packages"
Get-ChildItem -Path $packagesPath -Recurse -Include "*.php", "*.xml", "*.ini" -File | ForEach-Object {
    $head = [byte[]](Get-Content -LiteralPath $_.FullName -Encoding Byte -ReadCount 0 -TotalCount 3)
    if ($head.Count -ge 3 -and $head[0] -eq 0xEF -and $head[1] -eq 0xBB -and $head[2] -eq 0xBF) {
        $bomFiles += $_.FullName
    }
}
if ($bomFiles.Count -gt 0) {
    Write-Host "ABORT: UTF-8 BOM detected in the following file(s):" -ForegroundColor Red
    $bomFiles | ForEach-Object { Write-Host "  $_" -ForegroundColor Red }
    throw "Strip the BOM and re-run. Set-Content -Encoding utf8 in Windows PowerShell 5.1 writes BOM by default. Use [System.IO.File]::WriteAllText with a UTF8Encoding(false), or pipe through `tail -c +4` from a unix shell to drop the leading 3 bytes."
}

$pkgDir         = Join-Path $scriptDir "packages\pkg_cstemplateintegrity"
$pkgManifest    = Join-Path $pkgDir "pkg_cstemplateintegrity.xml"

if (-not (Test-Path $pkgManifest)) {
    throw "Package manifest not found at $pkgManifest"
}

if ([string]::IsNullOrEmpty($Version)) {
    $manifestContents = Get-Content $pkgManifest -Raw
    if ($manifestContents -match '<version>([^<]+)</version>') {
        $Version = $matches[1]
    } else {
        throw "Could not read <version> from $pkgManifest"
    }
}

$childExtensions = @(
    @{
        Name      = "com_cstemplateintegrity"
        SourceDir = Join-Path $scriptDir "packages\com_cstemplateintegrity"
        Contents  = @("cstemplateintegrity.xml", "script.php", "admin", "api", "media")
    },
    @{
        Name      = "plg_webservices_cstemplateintegrity"
        SourceDir = Join-Path $scriptDir "packages\plg_webservices_cstemplateintegrity"
        Contents  = @("cstemplateintegrity.xml", "services", "src", "language")
    },
    @{
        Name      = "plg_task_cstemplateintegrity"
        SourceDir = Join-Path $scriptDir "packages\plg_task_cstemplateintegrity"
        Contents  = @("cstemplateintegrity.xml", "services", "src", "forms", "language")
    }
)

$timestamp   = Get-Date -Format "yyyyMMdd_HHmm"
$pkgZipName  = "pkg_cstemplateintegrity_v${Version}_${timestamp}.zip"
$pkgZipPath  = Join-Path $scriptDir $pkgZipName

# Clean old builds
Get-ChildItem -Path $scriptDir -Filter "pkg_cstemplateintegrity_v${Version}*.zip" | Remove-Item -Force
Get-ChildItem -Path $scriptDir -Filter "com_cstemplateintegrity_v${Version}*.zip" | Remove-Item -Force
Get-ChildItem -Path $scriptDir -Filter "plg_webservices_cstemplateintegrity_v${Version}*.zip" | Remove-Item -Force
Get-ChildItem -Path $scriptDir -Filter "plg_task_cstemplateintegrity_v${Version}*.zip" | Remove-Item -Force

$pkgStage = Join-Path $scriptDir "build"
if (Test-Path $pkgStage) { Remove-Item $pkgStage -Recurse -Force }
New-Item -ItemType Directory -Path $pkgStage | Out-Null
New-Item -ItemType Directory -Path (Join-Path $pkgStage "packages") | Out-Null

Write-Host "Building pkg_cstemplateintegrity v$Version ..." -ForegroundColor Cyan

# 1. Build each child extension into the staging packages/ folder with
#    a stable, non-timestamped filename.
foreach ($ext in $childExtensions) {
    Write-Host "  Child: $($ext.Name)" -ForegroundColor DarkCyan

    $childStage = Join-Path $scriptDir "build-child"
    if (Test-Path $childStage) { Remove-Item $childStage -Recurse -Force }
    New-Item -ItemType Directory -Path $childStage | Out-Null

    foreach ($item in $ext.Contents) {
        $source = Join-Path $ext.SourceDir $item
        if (Test-Path $source) {
            $dest = Join-Path $childStage $item
            if ((Get-Item $source).PSIsContainer) {
                Copy-Item $source $dest -Recurse
            } else {
                Copy-Item $source $dest
            }
        }
    }

    $childZipPath = Join-Path $pkgStage "packages\$($ext.Name).zip"

    Push-Location $childStage
    try {
        & $sevenZip a -tzip $childZipPath * | Out-Null
        if ($LASTEXITCODE -ne 0) { throw "7-Zip failed on $($ext.Name) (exit $LASTEXITCODE)" }
    } finally {
        Pop-Location
    }

    Remove-Item $childStage -Recurse -Force
}

# 2. Copy the package manifest, script, and language files into the staging root.
Copy-Item $pkgManifest (Join-Path $pkgStage "pkg_cstemplateintegrity.xml")
Copy-Item (Join-Path $pkgDir "script.php") (Join-Path $pkgStage "script.php")
# Recursive copy of the entire language/ tree so every locale's
# pkg_cstemplateintegrity.sys.ini ends up in the zip. The manifest's
# <languages> block declares 15 locales; up through v2.4.3 only en-GB was
# being copied, which JED Checker flagged as "File not found" for the
# other 14 entries.
Copy-Item -Path (Join-Path $pkgDir "language") -Destination $pkgStage -Recurse

# 3. Zip the whole package.
Push-Location $pkgStage
try {
    & $sevenZip a -tzip $pkgZipPath * | Out-Null
    if ($LASTEXITCODE -ne 0) { throw "7-Zip failed on package (exit $LASTEXITCODE)" }
} finally {
    Pop-Location
}

Remove-Item $pkgStage -Recurse -Force

$sizeKb = [Math]::Round((Get-Item $pkgZipPath).Length / 1KB, 1)
Write-Host "Created $pkgZipName ($sizeKb KB)" -ForegroundColor Green
