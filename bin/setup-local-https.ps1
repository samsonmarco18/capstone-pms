$ErrorActionPreference = 'Stop'

$projectRoot = Split-Path -Parent $PSScriptRoot
$localAppData = [Environment]::GetFolderPath('LocalApplicationData')
$certDir = if ([string]::IsNullOrWhiteSpace($localAppData)) {
    Join-Path $projectRoot 'bin\local-cert'
} else {
    Join-Path $localAppData 'ERNI-PMS\local-cert'
}
$opensslExe = 'C:\xampppp\apache\bin\openssl.exe'
$opensslConfig = 'C:\xampppp\apache\conf\openssl.cnf'

if (-not (Test-Path -LiteralPath $opensslExe)) {
    throw "OpenSSL not found at $opensslExe"
}

if (-not (Test-Path -LiteralPath $opensslConfig)) {
    throw "OpenSSL config not found at $opensslConfig"
}

New-Item -ItemType Directory -Force -Path $certDir | Out-Null

$rootKey = Join-Path $certDir 'rootCA.key'
$rootCrt = Join-Path $certDir 'rootCA.crt'
$serverKey = Join-Path $certDir 'localhost.key'
$serverCsr = Join-Path $certDir 'localhost.csr'
$serverCrt = Join-Path $certDir 'localhost.crt'
$serverExt = Join-Path $certDir 'localhost.ext'
$env:OPENSSL_CONF = $opensslConfig

if (-not (Test-Path -LiteralPath $rootKey)) {
    & $opensslExe genrsa -out $rootKey 2048
}

if (-not (Test-Path -LiteralPath $rootCrt)) {
    & $opensslExe req -config $opensslConfig -x509 -new -nodes -key $rootKey -sha256 -days 3650 `
        -out $rootCrt `
        -subj '/C=PH/ST=Metro Manila/L=Manila/O=ERNI PMS Local Dev/CN=ERNI PMS Local Root CA'
}

if (-not (Test-Path -LiteralPath $serverKey)) {
    & $opensslExe genrsa -out $serverKey 2048
}

@"
authorityKeyIdentifier=keyid,issuer
basicConstraints=CA:FALSE
keyUsage = digitalSignature, keyEncipherment
extendedKeyUsage = serverAuth
subjectAltName = @alt_names

[alt_names]
DNS.1 = localhost
IP.1 = 127.0.0.1
IP.2 = ::1
"@ | Set-Content -Path $serverExt -Encoding ascii

& $opensslExe req -config $opensslConfig -new -key $serverKey -out $serverCsr `
    -subj '/C=PH/ST=Metro Manila/L=Manila/O=ERNI PMS Local Dev/CN=localhost'

& $opensslExe x509 -req -in $serverCsr -CA $rootCrt -CAkey $rootKey -CAcreateserial `
    -out $serverCrt -days 825 -sha256 -extfile $serverExt

Write-Host "Generated files in $certDir"
Write-Host "Root CA: $rootCrt"
Write-Host "Server cert: $serverCrt"
Write-Host "Server key: $serverKey"
