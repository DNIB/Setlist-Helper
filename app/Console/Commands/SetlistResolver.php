<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Process;

class SetlistResolver extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:setlist-resolver
                            {videoId}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Resolve setlist on Youtube';

    /**
     * Execute the console command.'
     */
    public function handle()
    {
        $videoId = $this->argument('videoId');
        $this->info('Resolving setlist for video ID: '.$videoId);

        $process = Process::run($this->getYtDlpCommand($this->argument('videoId')));
        if ($process->failed()) {
            $this->error('Resolving setlist failed: '.$process->errorOutput());

            return self::FAILURE;
        }

        $setlists = StandardSetlistItem::fromStringAll($process->output());

        $this->newLine();
        $this->table(
            ['Time', 'Song', 'Artist'],
            $setlists->map(fn (SetlistItemBase $item) => [$item->toTimeFormat(), $item->getSongName(), $item->getArtist()])
                ->all(),
        );
    }

    private function getYtDlpCommand(string $videoId): string
    {
        return sprintf(
            'yt-dlp -j --skip-download --write-comments %s | jq -r ".comments[].text"',
            $videoId,
        );
    }
}

interface SetlistItem
{
    public function getHour(): int;

    public function getMinute(): int;

    public function getSecond(): int;

    public function getSongName(): string;

    public function getArtist(): string;

    public function toTimeFormat(): string;
}

abstract readonly class SetlistItemBase implements SetlistItem
{
    /**
     * @return Collection<int,static>
     */
    abstract public static function fromStringAll(string $string): Collection;

    public function toTimeFormat(): string
    {
        return sprintf('%02d:%02d:%02d', $this->getHour(), $this->getMinute(), $this->getSecond());
    }
}

final readonly class StandardSetlistItem extends SetlistItemBase
{
    private const REGEX = '/(\d{1,2}:?\d{1,2}:\d{1,2})([ ]*[-~\/／]?[ ]*)(.*)?/';

    public function __construct(
        private int $hour,
        private int $minute,
        private int $second,
        private string $songName,
        private string $artist,
    ) {}

    public static function fromStringAll(string $string): Collection
    {
        preg_match_all(self::REGEX, $string, $matches);

        $items = collect();
        $count = count(head($matches));

        for ($i = 0; $i < $count; $i++) {
            $timeArguments = explode(':', data_get($matches, "1.$i", ''));
            $second = array_pop($timeArguments) ?? 0;
            $minute = array_pop($timeArguments) ?? 0;
            $hour = array_pop($timeArguments) ?? 0;

            $songArguments = explode('/', data_get($matches, "3.$i", ''));
            $artist = count($songArguments) > 1 ? array_pop($songArguments) : '';
            $songName = implode('/', $songArguments);

            $items->push(new StandardSetlistItem(
                (int) $hour,
                (int) $minute,
                (int) $second,
                StrOutputFormatter::format($songName),
                StrOutputFormatter::format($artist),
            ));
        }

        return $items;
    }

    public function getHour(): int
    {
        return $this->hour;
    }

    public function getMinute(): int
    {
        return $this->minute;
    }

    public function getSecond(): int
    {
        return $this->second;
    }

    public function getSongName(): string
    {
        return $this->songName;
    }

    public function getArtist(): string
    {
        return $this->artist;
    }

    public function info(): string
    {
        return empty($this->artist)
            ? sprintf(
                '%02d:%02d:%02d ~ %s',
                $this->hour,
                $this->minute,
                $this->second,
                $this->songName,
            )
            : sprintf(
                '%02d:%02d:%02d ~ %s / %s',
                $this->hour,
                $this->minute,
                $this->second,
                $this->songName,
                $this->artist,
            );
    }
}

class StrOutputFormatter
{
    public static function format(string $s): string
    {
        return str($s)
            ->trim()
            ->when(str_starts_with($s, '[') && str_ends_with($s, ']'))->between('[', ']')
            ->when(str_starts_with($s, '【') && str_ends_with($s, '】'))->between('【', '】')
            ->trim()
            ->toString();
    }
}
