[CmdletBinding()]
param(
    [int] $SensorId = 1,
    [int] $TestBadgeId = 9999
)

$ErrorActionPreference = 'Stop'

$projectRoot = Split-Path -Parent $PSScriptRoot
$envFile = Join-Path $projectRoot '.env'
$mysql = 'C:\Program Files\MySQL\MySQL Server 8.0\bin\mysql.exe'
$testTimestamp = Get-Date -Format 'yyyy-MM-dd HH:mm:ss'
$postAttempted = $false
$exitCode = 0
$tenantId = $null
$previousMysqlPassword = $env:MYSQL_PWD

function Read-EnvFile([string] $Path) {
    $settings = @{}

    foreach ($line in Get-Content -LiteralPath $Path) {
        if ($line -match '^\s*([^#=]+?)\s*=\s*(.*?)\s*$') {
            $key = $matches[1].Trim()
            $value = $matches[2].Trim()
            $value = $value.Trim([char] 39).Trim([char] 34)
            $settings[$key] = $value
        }
    }

    return $settings
}

function Invoke-MySql([string] $Query) {
    $sqlOutput = & $mysql `
        "--host=$($databaseSettings['database.default.hostname'])" `
        "--user=$($databaseSettings['database.default.username'])" `
        "--database=$($databaseSettings['database.default.database'])" `
        '--batch' '--skip-column-names' `
        -e $Query

    if ($LASTEXITCODE -ne 0) {
        throw "MySQL a échoué avec le code $LASTEXITCODE. Vérifier le client, le service MySQL et les paramètres du .env."
    }

    return ($sqlOutput -join "`n").Trim()
}

try {
    Write-Host '=== Test ADMS : simulation capteur ===' -ForegroundColor Cyan

    if (-not (Test-Path -LiteralPath $envFile)) {
        throw "Fichier .env introuvable : $envFile"
    }
    if (-not (Test-Path -LiteralPath $mysql)) {
        throw "Client MySQL introuvable : $mysql"
    }

    $databaseSettings = Read-EnvFile $envFile
    $requiredSettings = @(
        'database.default.hostname',
        'database.default.username',
        'database.default.password',
        'database.default.database',
        'app.baseURL'
    )

    foreach ($setting in $requiredSettings) {
        if (-not $databaseSettings.ContainsKey($setting) -or [string]::IsNullOrWhiteSpace($databaseSettings[$setting])) {
            throw "Paramètre manquant dans .env : $setting"
        }
    }

    $env:MYSQL_PWD = $databaseSettings['database.default.password']
    $baseUrl = $databaseSettings['app.baseURL'].TrimEnd('/')
    $sensorSql = "SELECT tenant_id, numero_serie, statut FROM capteurs WHERE id = $SensorId AND deleted_at IS NULL LIMIT 1;"
    $sensorResult = Invoke-MySql $sensorSql

    if ([string]::IsNullOrWhiteSpace($sensorResult)) {
        throw "Capteur #$SensorId introuvable dans la base."
    }

    $sensorFields = $sensorResult -split "`t"
    if ($sensorFields.Count -lt 3) {
        throw "Réponse inattendue lors de la lecture du capteur #$SensorId."
    }

    $tenantId = [int] $sensorFields[0]
    $serialNumber = [string] $sensorFields[1]
    $sensorStatus = [string] $sensorFields[2]

    if ($sensorStatus -ne 'ACTIF') {
        throw "Le capteur #$SensorId n’est pas actif (statut=$sensorStatus)."
    }
    if ([string]::IsNullOrWhiteSpace($serialNumber)) {
        throw "Le numéro de série du capteur #$SensorId est vide."
    }

    $escapedSerial = [uri]::EscapeDataString($serialNumber)
    $testUrl = "$baseUrl/iclock/cdata?SN=$escapedSerial&table=ATTLOG"

    Write-Host "`n[1] GET /iclock/cdata (config)" -ForegroundColor Yellow
    $configUrl = "$baseUrl/iclock/cdata?SN=$escapedSerial&options=all"
    $responseConfig = Invoke-WebRequest -Uri $configUrl -TimeoutSec 10
    Write-Host "Status : $($responseConfig.StatusCode)" -ForegroundColor Green
    $configLength = [Math]::Min(200, $responseConfig.Content.Length)
    Write-Host "Content : $($responseConfig.Content.Substring(0, $configLength))"

    $existingSql = @"
SELECT COUNT(*)
FROM pointages
WHERE tenant_id = $tenantId
  AND capteur_id = $SensorId
  AND badge_id = $TestBadgeId
  AND date_pointage = '$testTimestamp'
  AND source = 'CAPTEUR';
"@
    if ([int] (Invoke-MySql $existingSql) -gt 0) {
        throw 'La ligne de test existe déjà pour cet horodatage; envoi annulé pour protéger les données existantes.'
    }

    Write-Host "`n[2] POST /iclock/cdata?table=ATTLOG (pointage test)" -ForegroundColor Yellow
    $body = "$TestBadgeId`t$testTimestamp`t0`t1`n"
    $postAttempted = $true
    $responsePost = Invoke-WebRequest -Method Post `
        -Uri $testUrl `
        -ContentType 'text/plain' `
        -Body $body `
        -TimeoutSec 10
    Write-Host "Status : $($responsePost.StatusCode)" -ForegroundColor Green
    Write-Host "Content : $($responsePost.Content)"

    Write-Host "`n[3] Vérification du pointage en base" -ForegroundColor Yellow
    $selectSql = @"
SELECT id, badge_id, date_pointage
FROM pointages
WHERE tenant_id = $tenantId
  AND capteur_id = $SensorId
  AND badge_id = $TestBadgeId
  AND date_pointage = '$testTimestamp'
  AND source = 'CAPTEUR'
ORDER BY id DESC
LIMIT 1;
"@
    $insertedRow = Invoke-MySql $selectSql
    if ([string]::IsNullOrWhiteSpace($insertedRow)) {
        throw 'Aucun pointage de test trouvé en base après la réponse HTTP.'
    }

    Write-Host $insertedRow -ForegroundColor Green
}
catch {
    Write-Host "ERREUR : $($_.Exception.Message)" -ForegroundColor Red
    $exitCode = 1
}
finally {
    if ($postAttempted -and $null -ne $tenantId) {
        Write-Host "`n[4] Nettoyage du pointage de test exact" -ForegroundColor Yellow
        $deleteSql = @"
DELETE FROM pointages
WHERE tenant_id = $tenantId
  AND capteur_id = $SensorId
  AND badge_id = $TestBadgeId
  AND date_pointage = '$testTimestamp'
  AND source = 'CAPTEUR';
SELECT ROW_COUNT();
"@

        try {
            $deletedRows = Invoke-MySql $deleteSql
            Write-Host "Ligne(s) de test supprimée(s) : $deletedRows" -ForegroundColor Green
        }
        catch {
            Write-Host "ATTENTION : nettoyage automatique impossible : $($_.Exception.Message)" -ForegroundColor Red
        }
    }

    if ($null -eq $previousMysqlPassword) {
        Remove-Item Env:MYSQL_PWD -ErrorAction SilentlyContinue
    }
    else {
        $env:MYSQL_PWD = $previousMysqlPassword
    }
}

Write-Host "`n=== Test terminé ===" -ForegroundColor Cyan
if ($exitCode -ne 0) {
    exit $exitCode
}