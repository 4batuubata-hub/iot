$files = Get-ChildItem -Path "c:\xampp\htdocs\iot" -Recurse -Filter "*.php"
foreach ($f in $files) {
    if ($f.Name -eq "master_ct.php") { continue }
    $txt = [System.IO.File]::ReadAllText($f.FullName)
    if ($txt -match "Data Operator</a>" -and $txt -notmatch "master_ct\.php") {
        $txt = $txt -replace '(<a href="<\?= BASE_URL \?>admin/data_operator\.php"[^>]*>.*?Data Operator</a>)', "`$1`r`n                <a href=`"<?= BASE_URL ?>admin/master_ct.php`">📋 Master Cycle Time (CT)</a>"
        [System.IO.File]::WriteAllText($f.FullName, $txt, [System.Text.Encoding]::UTF8)
        Write-Host "Updated $($f.FullName)"
    }
}
