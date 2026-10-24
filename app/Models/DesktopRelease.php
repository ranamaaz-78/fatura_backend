<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** The Windows app installer on offer on the public website. Only the newest one is kept. */
class DesktopRelease extends Model
{
    protected $fillable = [
        'version',
        'notes',
        'file_path',
        'file_name',
        'size_bytes',
        'sha256',
        'sha512',
        'uploaded_by',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
        ];
    }

    /** The release the website offers: the newest one. */
    public static function current(): ?self
    {
        return static::query()->latest('id')->first();
    }

    public function scopeOlderThan(Builder $query, self $release): Builder
    {
        return $query->where('id', '<', $release->id);
    }

    /** What the website shows next to the download button. */
    public function toPublicArray(): array
    {
        return [
            'version' => $this->version,
            'notes' => $this->notes,
            'file_name' => $this->file_name,
            'size_bytes' => $this->size_bytes,
            'sha256' => $this->sha256,
            'published_at' => $this->created_at?->toIso8601String(),
            'download_url' => '/public/desktop-app/download',
        ];
    }

    /**
     * The file the installed app's updater (electron-updater, "generic" provider) reads to learn there is a new version.
     * The installer's address is relative to it: /public/desktop-app/updates/{file_name}.
     */
    public function toUpdateManifest(): string
    {
        $date = $this->created_at?->toIso8601String() ?? now()->toIso8601String();
        $notes = $this->notes === null || $this->notes === '' ? null : json_encode($this->notes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        // JSON strings are valid YAML, so names and notes are written that way: no quoting rules to get wrong.
        $lines = [
            'version: '.$this->version,
            'files:',
            '  - url: '.json_encode($this->file_name, JSON_UNESCAPED_SLASHES),
            '    sha512: '.$this->sha512,
            '    size: '.$this->size_bytes,
            'path: '.json_encode($this->file_name, JSON_UNESCAPED_SLASHES),
            'sha512: '.$this->sha512,
            "releaseDate: '".$date."'",
        ];

        if ($notes !== null) {
            $lines[] = 'releaseNotes: '.$notes;
        }

        return implode("\n", $lines)."\n";
    }
}
