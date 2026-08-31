# Dot-source once per shell:  . .\dev.ps1
#
# Everything here is scoped to the current shell. Nothing machine-wide
# changes, and closing the terminal undoes all of it.

$env:PHPRC = $PSScriptRoot

# --- Interpreter -----------------------------------------------------------
#
# The PHP on PATH machine-wide is 8.1, which the other Laravel 10 projects use
# and which is itself end-of-life. Laravel 13 requires php ^8.3, so this project
# needs a newer interpreter - but only this project. Prepending here switches it
# for this shell alone and leaves every other project on whatever PATH gives.
#
# 8.4 is preferred over 8.3: both satisfy ^8.3, but 8.4 has about two more years
# of security support. 8.5 also qualifies. Laravel 10 supports 8.1 to 8.3, so an
# 8.3 install would be safe for the other projects if you ever wanted it
# globally - 8.4 and 8.5 would not be.
#
# php.ini needs no changes for any of this: extension_dir is the relative "ext"
# and the extension names carry no version, so one ini serves every install.

$phpCandidates = @(
    'C:\Program Files\php84',
    'C:\Program Files\php8.4',
    'C:\tools\php84',
    'C:\Program Files\php83',
    'C:\Program Files\php8.3',
    'C:\tools\php83'
)

$phpDir = $null
foreach ($candidate in $phpCandidates) {
    if (Test-Path (Join-Path $candidate 'php.exe')) {
        $phpDir = $candidate
        break
    }
}

if ($phpDir) {
    # Idempotent - dot-sourcing twice in one shell must not stack duplicates.
    if (($env:PATH -split ';') -notcontains $phpDir) {
        $env:PATH = "$phpDir;$env:PATH"
    }
    Write-Host "PHP     -> $phpDir" -ForegroundColor Green
} else {
    Write-Host "PHP     -> no 8.3/8.4 install found; using whatever is on PATH" -ForegroundColor Yellow
    Write-Host "           Laravel 13 needs PHP 8.3+. Unzip the latest 8.4 (thread-safe," -ForegroundColor Yellow
    Write-Host "           x64) to 'C:\Program Files\php84' and re-run this script." -ForegroundColor Yellow
    Write-Host "           Nothing else needs changing." -ForegroundColor Yellow
}

# Report what actually resolved, rather than what was intended.
#
# `php -v` rather than `php -r`, because PowerShell 5.1 mangles quotes passed to
# a native exe. And Get-Command rather than redirecting stderr with 2>$null:
# that redirect wraps a native command's stderr in ErrorRecords and leaves $?
# false even on a clean exit, which made dot-sourcing this script report failure
# while printing the right thing.
if (Get-Command php -ErrorAction SilentlyContinue) {
    # Collect every line first, then take the first. Piping the native command
    # straight into `Select-Object -First 1` short-circuits the pipeline and
    # stops php mid-stream, which leaves $LASTEXITCODE at -1 - so dot-sourcing
    # this script ended with a 255 exit status while printing the right thing.
    $phpVersionLines = @(& php -v)
    Write-Host "Active  -> $($phpVersionLines[0])" -ForegroundColor Green
} else {
    Write-Host "Active  -> php not found on PATH" -ForegroundColor Red
}

# --- Config ----------------------------------------------------------------

Write-Host "PHPRC   -> $env:PHPRC (loads ./php.ini with intl + pdo_sqlite enabled)" -ForegroundColor Green
Write-Host "Verify: php -m | findstr `"intl pdo_sqlite`""
