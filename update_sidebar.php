<?php
$dir = new RecursiveDirectoryIterator(__DIR__);
$ite = new RecursiveIteratorIterator($dir);
$files = new RegexIterator($ite, '/^.+\.php$/i', RecursiveRegexIterator::GET_MATCH);

foreach($files as $file) {
    $path = $file[0];
    if (strpos($path, 'master_ct.php') !== false) continue;
    
    $content = file_get_contents($path);
    if (strpos($content, 'Data Operator</a>') !== false && strpos($content, 'master_ct.php') === false) {
        // We want to insert the master_ct link right after Data Operator
        $search = '/(<a href="<\?= BASE_URL \?>admin\/data_operator\.php"[^>]*>.*?Data Operator<\/a>)/';
        $replace = "$1\n                <a href=\"<?= BASE_URL ?>admin/master_ct.php\">📋 Master Cycle Time (CT)</a>";
        
        $newContent = preg_replace($search, $replace, $content);
        if ($newContent !== $content) {
            file_put_contents($path, $newContent);
            echo "Updated: $path\n";
        }
    }
}
echo "Done.";
?>
