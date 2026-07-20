# Build a WordPress-ready plugin ZIP with the canonical lowercase folder slug.
$ErrorActionPreference = 'Stop'

$Root = Split-Path -Parent $PSScriptRoot
$Slug = 'tso-swiss-knife-advanced-maintenance-developer-toolkit'
$Dist = Join-Path $Root 'dist'
$Stage = Join-Path $Dist $Slug
$Zip = Join-Path $Dist "$Slug.zip"

if (Test-Path $Dist) {
    Remove-Item -Recurse -Force $Dist
}
New-Item -ItemType Directory -Path $Stage -Force | Out-Null

$Items = @(
    'assets',
    'includes',
    'languages',
    'mu-plugin',
    'tso-swiss-knife.php',
    'uninstall.php',
    'readme.txt',
    'LICENSE'
)

foreach ($Item in $Items) {
    $Src = Join-Path $Root $Item
    if (Test-Path $Src) {
        $Dest = Join-Path $Stage $Item
        Copy-Item -Path $Src -Destination $Dest -Recurse -Force
    }
}

if (Test-Path $Zip) {
    Remove-Item -Force $Zip
}

Add-Type -AssemblyName System.IO.Compression.FileSystem
[System.IO.Compression.ZipFile]::CreateFromDirectory($Stage, $Zip)

Write-Host "Created $Zip"
Write-Host "Folder inside ZIP: $Slug/"
