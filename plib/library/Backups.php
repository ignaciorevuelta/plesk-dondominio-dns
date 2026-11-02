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
        $file = sprintf('%s/%s-%s-%s.json', $path, date('Ymd-His'), substr(uniqid(), -6), $side);
        if (file_put_contents($file, json_encode($records, JSON_PRETTY_PRINT)) === false) {
            throw new RuntimeException("No se puede escribir $file");
        }
        $all = glob("$path/*.json");
        sort($all);
        foreach (array_slice($all, 0, max(0, count($all) - $this->keep)) as $old) {
            unlink($old);
        }
    }
}
