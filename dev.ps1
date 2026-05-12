$env:PHPRC = $PSScriptRoot
Write-Host "PHPRC set to $env:PHPRC (loads ./php.ini with intl + pdo_sqlite enabled)" -ForegroundColor Green
Write-Host "Verify: php -m | findstr `"intl pdo_sqlite`""
