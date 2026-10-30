$ErrorActionPreference = 'Stop'
$repo = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
foreach ($file in (Get-ChildItem -LiteralPath $repo -Filter '*.php' -File -Recurse | Where-Object { $_.FullName -notmatch '[\\/](vendor|node_modules|dist)[\\/]' })) {
    & php -l $file.FullName
    if ($LASTEXITCODE -ne 0) { throw "PHP lint failed: $($file.FullName)" }
}
& node --check (Join-Path $repo 'assets/js/checkout-blocks.js')
if ($LASTEXITCODE -ne 0) { throw 'JavaScript syntax check failed.' }
& php (Join-Path $repo 'tests/php/payment-regression.php')
if ($LASTEXITCODE -ne 0) { throw 'Payment regression tests failed.' }
