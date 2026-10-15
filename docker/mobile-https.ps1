param(
    [Parameter(Mandatory = $true)]
    [ValidateSet('Setup', 'Start', 'Stop', 'Status', 'Verify', 'FirewallCommand', 'Cleanup')]
    [string]$Action,
    [string]$LanIP = '192.168.1.218',
    [ValidateRange(1024, 65535)][int]$HttpsPort = 8443
)
$ErrorActionPreference = 'Stop'
$workspacePath = [IO.Path]::GetFullPath((Split-Path $PSScriptRoot -Parent))
Set-Location -LiteralPath $workspacePath
$localTlsPath = Join-Path $workspacePath '.local-mobile-https'
$statePath = Join-Path $localTlsPath 'state.json'
$mkcertPath = Join-Path $localTlsPath 'mkcert.exe'
$caRootPath = Join-Path $env:LOCALAPPDATA 'Shishli\mobile-https-ca'
$composeArgs = @('compose', '-p', 'shishli', '-f', 'compose.yaml', '-f', 'docker/compose.mobile-https.yaml', '--profile', 'mobile-https')
function Invoke-Docker([string[]]$Arguments) {
    & docker @Arguments
    if ($LASTEXITCODE -ne 0) { throw "Docker command failed: $($Arguments -join ' ')" }
}
function Read-Certificate([string]$Path) {
    $pem = [IO.File]::ReadAllText($Path)
    if ($pem -match 'PRIVATE KEY' -or $pem -notmatch 'BEGIN CERTIFICATE') { throw 'Expected a public certificate only.' }
    $base64 = $pem.Replace('-----BEGIN CERTIFICATE-----', '').Replace('-----END CERTIFICATE-----', '') -replace '\s', ''
    return New-Object Security.Cryptography.X509Certificates.X509Certificate2 -ArgumentList @(,[Convert]::FromBase64String($base64))
}
function Assert-Wifi() {
    $address = Get-NetIPAddress -InterfaceAlias 'Wi-Fi' -AddressFamily IPv4 | Where-Object IPAddress -eq $LanIP
    if (-not $address -or $address.PrefixLength -ne 24) { throw "Expected Wi-Fi address $LanIP/24. Inspect the network before changing the certificate/binding." }
}
if ($Action -ne 'Setup' -and (Test-Path -LiteralPath $statePath)) {
    $state = Get-Content -Raw -LiteralPath $statePath | ConvertFrom-Json
    $LanIP = $state.lanIP; $HttpsPort = [int]$state.port; $caRootPath = $state.caRoot
}
$parsedLanIP = $null
if (-not [Net.IPAddress]::TryParse($LanIP, [ref]$parsedLanIP) -or $LanIP -notmatch '^192\.168\.1\.(?:[1-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-4])$') { throw 'Only a concrete 192.168.1.x LAN IPv4 is supported; never bind 0.0.0.0.' }
$resolvedCaRoot = [IO.Path]::GetFullPath($caRootPath)
if ($resolvedCaRoot -eq $workspacePath -or $resolvedCaRoot.StartsWith($workspacePath + [IO.Path]::DirectorySeparatorChar, [StringComparison]::OrdinalIgnoreCase)) { throw 'CAROOT must be outside the workspace.' }
$env:SHISHLI_MOBILE_LAN_IP = $LanIP
$env:SHISHLI_MOBILE_HTTPS_PORT = [string]$HttpsPort

switch ($Action) {
    'Setup' {
        Assert-Wifi
        New-Item -ItemType Directory -Path $localTlsPath -Force | Out-Null
        if (-not (Test-Path -LiteralPath $mkcertPath)) {
            $downloadUrl = 'https://github.com/FiloSottile/mkcert/releases/download/v1.4.4/mkcert-v1.4.4-windows-amd64.exe'
            & curl.exe --fail --location --proto '=https' --tlsv1.2 --output $mkcertPath $downloadUrl
            if ($LASTEXITCODE -ne 0) { throw 'mkcert download failed.' }
        }
        $expectedHash = 'D2660B50A9ED59EADA480750561C96ABC2ED4C9A38C6A24D93E30E0977631398'
        if ((Get-FileHash -LiteralPath $mkcertPath -Algorithm SHA256).Hash -ne $expectedHash) { throw 'mkcert checksum does not match the pinned Microsoft WinGet manifest.' }
        $previousCaRoot = $env:CAROOT; $previousTrustStores = $env:TRUST_STORES
        try {
            $env:CAROOT = $caRootPath; $env:TRUST_STORES = 'system'
            & $mkcertPath -install
            if ($LASTEXITCODE -ne 0) { throw 'mkcert root trust installation failed.' }
            & $mkcertPath -cert-file (Join-Path $localTlsPath 'server.crt') -key-file (Join-Path $localTlsPath 'server.key') $LanIP localhost 127.0.0.1
            if ($LASTEXITCODE -ne 0) { throw 'Server certificate generation failed.' }
        } finally { $env:CAROOT = $previousCaRoot; $env:TRUST_STORES = $previousTrustStores }
        $caCertificate = Read-Certificate (Join-Path $caRootPath 'rootCA.pem')
        [IO.File]::WriteAllBytes((Join-Path $localTlsPath 'shishli-rootCA.crt'), $caCertificate.RawData)
        $leafCertificate = Read-Certificate (Join-Path $localTlsPath 'server.crt')
        $san = ($leafCertificate.Extensions | Where-Object { $_.Oid.Value -eq '2.5.29.17' }).Format($false)
        if ($san -notmatch [regex]::Escape($LanIP) -or $leafCertificate.NotAfter -le (Get-Date)) { throw 'Server certificate SAN or validity check failed.' }
        $template = Get-Content -Raw -Encoding utf8 -LiteralPath (Join-Path $PSScriptRoot 'nginx/mobile-https.conf.template')
        $rendered = $template.Replace('__LAN_IP__', $LanIP).Replace('__HTTPS_PORT__', [string]$HttpsPort)
        [IO.File]::WriteAllText((Join-Path $localTlsPath 'nginx-mobile.conf'), $rendered, [Text.UTF8Encoding]::new($false))
        $state = @{lanIP=$LanIP;port=$HttpsPort;caRoot=$caRootPath;origin="https://${LanIP}:$HttpsPort";rootThumbprint=$caCertificate.Thumbprint;leafNotAfter=$leafCertificate.NotAfter.ToUniversalTime().ToString('o')}
        [IO.File]::WriteAllText($statePath, ($state | ConvertTo-Json), [Text.UTF8Encoding]::new($false))
        Write-Output "Ready: $($state.origin)"
        Write-Output "Android public CA only: $(Join-Path $localTlsPath 'shishli-rootCA.crt')"
        Write-Output "Private CA stays outside Docker/workspace: $caRootPath"
    }
    'Start' {
        Assert-Wifi
        foreach ($name in @('server.crt','server.key','nginx-mobile.conf')) { if (-not (Test-Path -LiteralPath (Join-Path $localTlsPath $name) -PathType Leaf)) { throw 'Run Setup first.' } }
        $upstreamId = & docker compose -p shishli ps -q nginx
        if ($LASTEXITCODE -ne 0 -or -not $upstreamId) { throw 'Existing shishli nginx must already be running; Start does not start other services.' }
        $existingId = & docker @composeArgs ps -q mobile-https
        if ($LASTEXITCODE -ne 0) { throw 'Cannot inspect mobile HTTPS service.' }
        if (-not $existingId) {
            $listener = New-Object Net.Sockets.TcpListener([Net.IPAddress]::Parse($LanIP), $HttpsPort)
            try { $listener.Start() } finally { $listener.Stop() }
        }
        Invoke-Docker ($composeArgs + @('config','--quiet'))
        Invoke-Docker ($composeArgs + @('run','--rm','--no-deps','--entrypoint','nginx','mobile-https','-t'))
        Invoke-Docker ($composeArgs + @('up','-d','--no-deps','--wait','mobile-https'))
        Write-Output "Android URL: https://${LanIP}:$HttpsPort"
    }
    'Stop' { Invoke-Docker ($composeArgs + @('stop','mobile-https')) }
    'Status' { Invoke-Docker ($composeArgs + @('ps','mobile-https')); & docker compose -p shishli ps }
    'Verify' {
        & node (Join-Path $PSScriptRoot 'verify-mobile-https.mjs')
        if ($LASTEXITCODE -ne 0) { throw 'HTTPS verification failed.' }
    }
    'FirewallCommand' {
        Assert-Wifi
        $profile = (Get-NetConnectionProfile -InterfaceAlias 'Wi-Fi').NetworkCategory.ToString()
        Write-Output "Wi-Fi profile: $profile. Review/run this exact rule in Administrator PowerShell; no rule was installed by this script."
        Write-Output "New-NetFirewallRule -Name 'Shishli-Mobile-HTTPS-8443' -DisplayName 'Shishli local Android HTTPS' -Direction Inbound -Action Allow -Protocol TCP -LocalPort $HttpsPort -LocalAddress $LanIP -RemoteAddress 192.168.1.0/24 -Profile $profile -InterfaceAlias 'Wi-Fi' -EdgeTraversalPolicy Block"
    }
    'Cleanup' {
        Invoke-Docker ($composeArgs + @('stop','mobile-https'))
        Invoke-Docker ($composeArgs + @('rm','-f','mobile-https'))
        $resolvedTlsPath = [IO.Path]::GetFullPath($localTlsPath)
        if ($resolvedTlsPath -ne (Join-Path $workspacePath '.local-mobile-https')) { throw 'Cleanup target is outside the intended workspace directory.' }
        foreach ($name in @('server.crt','server.key','nginx-mobile.conf','state.json','shishli-rootCA.crt')) {
            $targetPath = Join-Path $resolvedTlsPath $name
            if (Test-Path -LiteralPath $targetPath -PathType Leaf) { Remove-Item -LiteralPath $targetPath }
        }
        Write-Output 'Removed only local server certificate/config and public export. The dedicated outside-project CA/trust is retained; see documentation for optional uninstall.'
    }
}
