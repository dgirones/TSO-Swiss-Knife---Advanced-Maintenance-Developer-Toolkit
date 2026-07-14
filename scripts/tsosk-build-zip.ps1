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
New-Item -ItemType Directory -Path $Stage | Out-Null

$Items = @(
	'assets',
	'includes',
	'languages',
	'mu-plugin',
	'scripts',
	'tso-swiss-knife.php',
	'uninstall.php',
	'readme.txt',
	'LICENSE'
)

foreach ($Item in $Items) {
	$Source = Join-Path $Root $Item
	if (Test-Path $Source) {
		Copy-Item -Path $Source -Destination (Join-Path $Stage $Item) -Recurse -Force
	}
}

if (Test-Path $Zip) {
	Remove-Item -Force $Zip
}

Compress-Archive -Path $Stage -DestinationPath $Zip -Force

Write-Host "Created $Zip"
Write-Host "Folder inside ZIP: $Slug/"
