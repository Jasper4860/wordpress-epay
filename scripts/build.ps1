param([string]$OutputDirectory = (Join-Path $PSScriptRoot '..\dist'))
$ErrorActionPreference = 'Stop'
$repo = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
$header = Get-Content -LiteralPath (Join-Path $repo 'epay.php') -Raw
$version = [regex]::Match($header, 'Version:\s+([0-9.]+)').Groups[1].Value
if (-not $version) { throw 'Plugin version not found.' }
$target = [IO.Path]::GetFullPath($OutputDirectory)
if ($target.StartsWith((Join-Path $repo 'includes'), [StringComparison]::OrdinalIgnoreCase)) { throw 'Invalid output directory.' }
New-Item -ItemType Directory -Path $target -Force | Out-Null
$package = Join-Path $target "epay-$version.zip"
$files = @('epay.php', 'LICENSE', 'README.md')
foreach ($folder in @('assets','includes')) {
    $files += Get-ChildItem -LiteralPath (Join-Path $repo $folder) -File -Recurse | ForEach-Object { [IO.Path]::GetRelativePath($repo, $_.FullName) }
}
$stream = [IO.File]::Open($package, [IO.FileMode]::Create)
$archive = [IO.Compression.ZipArchive]::new($stream, [IO.Compression.ZipArchiveMode]::Create)
try {
    foreach ($relative in ($files | Sort-Object)) {
        $entry = $archive.CreateEntry(('epay/' + $relative.Replace('\','/')), [IO.Compression.CompressionLevel]::Optimal)
        $entry.LastWriteTime = [DateTimeOffset]::new(2000,1,1,0,0,0,[TimeSpan]::Zero)
        $writer = $entry.Open()
        $reader = [IO.File]::OpenRead((Join-Path $repo $relative))
        try { $reader.CopyTo($writer) } finally { $reader.Dispose(); $writer.Dispose() }
    }
} finally { $archive.Dispose(); $stream.Dispose() }
$hash = (Get-FileHash -LiteralPath $package -Algorithm SHA256).Hash.ToLowerInvariant()
[IO.File]::WriteAllText(($package + '.sha256'), ($hash + '  ' + [IO.Path]::GetFileName($package) + "`n"))
Write-Output "Built $package"
Write-Output "Runtime files: $($files.Count)"
Write-Output "SHA256: $hash"
