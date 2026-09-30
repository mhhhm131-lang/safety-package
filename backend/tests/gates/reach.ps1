# جدول الوصول (جرد ٢٠٢٦-٠٩-٢٩، قرار ٦٧): لكل مسار باسم، الملفات التي تحوي route('اسمه') في الواجهة (views) والكود (app).
# «الباب» = الملف الذي يحوي الرابط. يُعاد في ٢٧-د: صفر حالة بلا بطاقة، وكل شاشة لها باب واحد.
# ما لا يلتقطه: أسماء مسارات تُبنى بالتركيب، وروابط JS حرفية — تُراجع يدوياً.
# التشغيل من backend/:  powershell -File tests\gates\reach.ps1 [-Out مسار.csv]
param([string]$Out = (Join-Path $env:TEMP 'reach.csv'))
$ErrorActionPreference = 'Stop'
$root = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
Set-Location $root
$routes = php artisan route:list --json | ConvertFrom-Json

$refs = @{}
$files = Get-ChildItem -Recurse -File -Path "$root\resources\views", "$root\app" -Include *.php
$rx = [regex]"route\(\s*'([a-z0-9_.\-]+)'"
foreach ($f in $files) {
  $txt = Get-Content $f.FullName -Raw -Encoding UTF8
  $rel = $f.FullName.Substring($root.Length + 1)
  foreach ($m in $rx.Matches($txt)) {
    $n = $m.Groups[1].Value
    if (-not $refs.ContainsKey($n)) { $refs[$n] = New-Object System.Collections.Generic.HashSet[string] }
    [void]$refs[$n].Add($rel)
  }
}

$rows = foreach ($r in $routes) {
  if (-not $r.name) { continue }
  $m = ($r.method -split '\|')[0]
  $views = @(); $ctrls = @()
  if ($refs.ContainsKey($r.name)) {
    foreach ($p in $refs[$r.name]) {
      if ($p -like 'resources\views*') { $views += $p.Replace('resources\views\', '').Replace('.blade.php', '') }
      else { $ctrls += $p.Replace('app\', '').Replace('.php', '') }
    }
  }
  [pscustomobject]@{
    method = $m; uri = $r.uri; name = $r.name; action = ($r.action -replace '^App\\', '')
    mw = (($r.middleware | Where-Object { $_ -match 'permission|role|auth|guest' }) -join ' ')
    views = ($views -join ' ; '); ctrls = ($ctrls -join ' ; ')
  }
}
$rows | Export-Csv -Path $Out -NoTypeInformation -Encoding UTF8
"rows: " + $rows.Count
"GET named web: " + ($rows | Where-Object { $_.method -eq 'GET' -and $_.uri -notmatch '^api/' }).Count
"GET with no view door: " + ($rows | Where-Object { $_.method -eq 'GET' -and $_.uri -notmatch '^api/' -and $_.views -eq '' }).Count
"written: $Out"
