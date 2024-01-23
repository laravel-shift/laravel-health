<?php

namespace Spatie\Health\Checks\Checks;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;
use Symfony\Component\HttpFoundation\File\File as SymfonyFile;

class BackupsCheck extends Check
{
    protected ?string $locatedAt = null;

    protected ?Carbon $youngestShouldHaveBeenMadeBefore = null;
    protected ?Carbon $oldestShouldHaveBeenMadeAfter = null;

    protected int $minimumSizeInMegabytes = 0;

    protected int $minimumNumberOfBackups = 0;

    public function locatedAt(string $globPath): self
    {
        $this->locatedAt = $globPath;

        return $this;
    }

    public function youngestBackShouldHaveBeenMadeBefore(Carbon $date): self
    {
        $this->youngestShouldHaveBeenMadeBefore = $date;

        return $this;
    }

    public function oldestBackShouldHaveBeenMadeAfter(Carbon $date): self
    {
        $this->oldestShouldHaveBeenMadeAfter = $date;

        return $this;
    }

    public function atLeastSizeInMb(int $minimumSizeInMegabytes): self
    {
        $this->minimumSizeInMegabytes = $minimumSizeInMegabytes;

        return $this;
    }

    public function atLeastNumberOfBackups(int $minimumNumberOfBackups): self
    {
        $this->minimumNumberOfBackups = $minimumNumberOfBackups;

        return $this;
    }

    public function run(): Result
    {
        $files = collect(File::glob($this->locatedAt));

        if ($files->isEmpty()) {
            return Result::make()->failed('No backups found');
        }

        $eligableBackups = $files
            ->map(function (string $path) {
                return new SymfonyFile($path);
            })
            ->filter(function (SymfonyFile $file) {
                return $file->getSize() >= $this->minimumSizeInMegabytes * 1024 * 1024;
            });

        if ($eligableBackups->isEmpty()) {
            return Result::make()->failed('No backups found that are large enough');
        }

        if ($eligableBackups->count() < $this->minimumNumberOfBackups) {
            return Result::make()->failed('Not enough backups found');
        }

        if ($this->youngestShouldHaveBeenMadeBefore) {
            if ($this->youngestBackupIsToolOld($eligableBackups)) {
                return Result::make()
                    ->failed("Youngest backup was too old");
            }
        }

        if ($this->oldestShouldHaveBeenMadeAfter) {
            if ($this->oldestBackupIsTooYoung($eligableBackups)) {
                return Result::make()
                    ->failed("Oldest backup was too young");
            }
        }

        return Result::make()->ok();
    }


    /**
     * @param Collection<SymfonyFile> $backups
     *
     * @return bool
     */
    protected function youngestBackupIsToolOld(Collection $backups): bool
    {
        /** @var SymfonyFile|null $youngestBackup */
        $youngestBackup = $backups
            ->sortByDesc(fn(SymfonyFile $file) => $file->getMTime())
            ->first();

        $threshold = $this->youngestShouldHaveBeenMadeBefore->getTimestamp();

        return $youngestBackup->getMTime() <= $threshold;
    }

    /**
     * @param Collection<SymfonyFile> $backups
     *
     * @return bool
     */
    protected function oldestBackupIsTooYoung(Collection $backups): bool
    {
        /** @var SymfonyFile|null $oldestBackup */
        $oldestBackup = $backups
            ->sortBy(fn(SymfonyFile $file) => $file->getMTime())
            ->first();

        $threshold = $this->oldestShouldHaveBeenMadeAfter->getTimestamp();

        return $oldestBackup->getMTime() >= $threshold;
    }
}