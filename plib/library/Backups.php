<?php // plib/library/Backups.php
class Modules_DondominioDns_Backups
{
    public function __construct(private string $dir, private int $keep = 20) {}

    public function save(string $domain, string $side, array $records): void
    {
        $path = $this->dir . '/' . basename($domain);
        if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) {
            throw new RuntimeException("No se puede crear $path");
        }
        $json = json_encode($records, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        $file = sprintf('%s/%s-%020d-%s.json', $path, gmdate('Ymd-His'), hrtime(true), $side);
        $written = file_put_contents($file, $json);
        if ($written !== strlen($json)) {
            @unlink($file);
            throw new RuntimeException("No se puede escribir $file");
        }
        $all = glob("$path/*.json");
        sort($all);
        foreach (array_slice($all, 0, max(0, count($all) - $this->keep)) as $old) {
            unlink($old);
        }
    }
}
