[CmdletBinding()]
param(
    [string]$BaseUrl = $env:AMS_E2E_BASE_URL,
    [string]$SuperAdminEmail = $env:AMS_E2E_SUPERADMIN_EMAIL,
    [string]$SuperAdminPassword = $env:AMS_E2E_SUPERADMIN_PASSWORD
)

$ErrorActionPreference = 'Stop'

if ([string]::IsNullOrWhiteSpace($BaseUrl)) {
    $BaseUrl = 'http://127.0.0.1:8080'
}
if ([string]::IsNullOrWhiteSpace($SuperAdminEmail) -or [string]::IsNullOrWhiteSpace($SuperAdminPassword)) {
    throw 'Définissez AMS_E2E_SUPERADMIN_EMAIL et AMS_E2E_SUPERADMIN_PASSWORD avec un compte de test.'
}

$BaseUrl = $BaseUrl.TrimEnd('/')
$SuperAdminToken = $null
$TenantId = $null
$TestSlug = "ams-e2e-$([guid]::NewGuid().ToString('N').Substring(0, 12))"
$TestAdminEmail = "$TestSlug-admin@example.test"
$TestUserEmail = "$TestSlug-user@example.test"
$TestPassword = [Convert]::ToBase64String([Security.Cryptography.RandomNumberGenerator]::GetBytes(18))
$Passed = [System.Collections.Generic.List[string]]::new()

function Invoke-AmsApi {
    param(
        [Parameter(Mandatory)][string]$Method,
        [Parameter(Mandatory)][string]$Path,
        [string]$Token,
        [object]$Body,
        [int[]]$ExpectedStatus = @(200)
    )

    $headers = @{}
    if ($Token) {
        $headers.Authorization = "Bearer $Token"
    }
    $request = @{
        Uri = "$BaseUrl$Path"
        Method = $Method
        Headers = $headers
        SkipHttpErrorCheck = $true
    }
    if ($null -ne $Body) {
        $request.ContentType = 'application/json'
        $request.Body = $Body | ConvertTo-Json -Depth 10 -Compress
    }

    $response = Invoke-WebRequest @request
    if ($response.StatusCode -notin $ExpectedStatus) {
        throw "$Method $Path a renvoyé HTTP $($response.StatusCode), attendu: $($ExpectedStatus -join ', ')."
    }
    if ($response.StatusCode -eq 204 -or [string]::IsNullOrWhiteSpace($response.Content)) {
        return [pscustomobject]@{ StatusCode = $response.StatusCode; Data = $null }
    }

    $json = $response.Content | ConvertFrom-Json
    if ($response.StatusCode -lt 400 -and $json.success -ne $true) {
        throw "$Method $Path a renvoyé une réponse sans succès."
    }
    return [pscustomobject]@{ StatusCode = $response.StatusCode; Data = $json.data }
}

function Assert-Check {
    param([string]$Name, [bool]$Condition)
    if (-not $Condition) {
        throw "Échec du contrôle E2E: $Name"
    }
    $Passed.Add($Name)
    Write-Host "[OK] $Name" -ForegroundColor Green
}

try {
    $login = Invoke-AmsApi -Method POST -Path '/api/auth/login' -ExpectedStatus @(200) -Body @{
        email = $SuperAdminEmail
        password = $SuperAdminPassword
    }
    $SuperAdminToken = $login.Data.access_token
    Assert-Check 'Connexion super-admin' (-not [string]::IsNullOrWhiteSpace($SuperAdminToken))

    $created = Invoke-AmsApi -Method POST -Path '/api/admin/tenants' -Token $SuperAdminToken -ExpectedStatus @(201) -Body @{
        name = "AMS E2E $TestSlug"
        slug = $TestSlug
        contact_email = $TestAdminEmail
        timezone = 'Africa/Bujumbura'
        admin_nom_complet = 'Administrateur E2E'
        admin_email = $TestAdminEmail
        admin_password = $TestPassword
    }
    $TenantId = [int]$created.Data.tenant.id
    Assert-Check 'Création tenant et compte admin' ($TenantId -gt 0 -and $created.Data.admin.email -eq $TestAdminEmail)

    $tenants = Invoke-AmsApi -Method GET -Path "/api/admin/tenants?search=$TestSlug&limit=10&offset=0" -Token $SuperAdminToken
    Assert-Check 'Liste admin filtrée par slug' (@($tenants.Data.items | Where-Object { $_.id -eq $TenantId }).Count -eq 1)

    $tenantLogin = Invoke-AmsApi -Method POST -Path '/api/auth/login' -ExpectedStatus @(200) -Body @{
        tenant_id = $TenantId
        email = $TestAdminEmail
        password = $TestPassword
    }
    $TenantToken = $tenantLogin.Data.access_token
    Assert-Check 'Connexion admin du tenant' (-not [string]::IsNullOrWhiteSpace($TenantToken))

    $denied = Invoke-AmsApi -Method GET -Path '/api/admin/tenants' -Token $TenantToken -ExpectedStatus @(403)
    Assert-Check 'Accès tenants refusé à un admin ordinaire' ($denied.StatusCode -eq 403)

    $badgeId = Get-Random -Minimum 100000000 -Maximum 2000000000
    $employee = Invoke-AmsApi -Method POST -Path '/api/employes' -Token $TenantToken -ExpectedStatus @(201) -Body @{
        matricule = $TestSlug
        badge_id = $badgeId
        nom = 'Test'
        prenom = 'E2E'
        email = "$TestSlug-employee@example.test"
    }
    Assert-Check 'Création employé' ($employee.Data.id -gt 0 -and $employee.Data.tenant_id -eq $TenantId)

    $managedUser = Invoke-AmsApi -Method POST -Path '/api/users' -Token $TenantToken -ExpectedStatus @(201) -Body @{
        email = $TestUserEmail
        nom_complet = 'Utilisateur E2E'
        password = $TestPassword
        role = 'lecteur'
        employe_id = $employee.Data.id
    }
    Assert-Check 'Création utilisateur' ($managedUser.Data.id -gt 0 -and $managedUser.Data.email -eq $TestUserEmail)

    $today = (Get-Date).ToString('yyyy-MM-dd')
    $report = Invoke-AmsApi -Method GET -Path "/api/rapports/quotidien?date=$today" -Token $TenantToken
    Assert-Check 'Génération rapport quotidien' ($null -ne $report.Data)

    $detail = Invoke-AmsApi -Method GET -Path "/api/admin/tenants/$TenantId" -Token $SuperAdminToken
    Assert-Check 'Détail tenant et compteurs' ($detail.Data.user_count -ge 2 -and $detail.Data.employee_count -ge 1)
}
finally {
    if ($TenantId -gt 0 -and -not [string]::IsNullOrWhiteSpace($SuperAdminToken)) {
        try {
            $null = Invoke-AmsApi -Method DELETE -Path "/api/admin/tenants/$TenantId" -Token $SuperAdminToken -ExpectedStatus @(200)
            Write-Host '[CLEANUP] Tenant E2E désactivé.' -ForegroundColor Yellow
        }
        catch {
            Write-Warning "Nettoyage automatique incomplet. Désactivez manuellement le tenant $TenantId (slug $TestSlug)."
        }
    }
}

Write-Host "Tests E2E réussis: $($Passed.Count)" -ForegroundColor Cyan
