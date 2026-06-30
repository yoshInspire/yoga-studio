<?php

namespace App\Filament\Resources\ClassSessions\Pages;

use App\Filament\Resources\ClassSessions\ClassSessionResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;

class ListClassSessions extends ListRecords
{
    public const HIDDEN_DATES_SESSION_KEY = 'admin.class_sessions.hidden_dates';

    protected static string $resource = ClassSessionResource::class;

    protected function getHeaderActions(): array
    {
        $actions = [
            CreateAction::make(),
        ];

        if (self::hasHiddenDates()) {
            $actions[] = Action::make('showHiddenDays')
                ->label('Показать скрытые дни')
                ->icon(Heroicon::Eye)
                ->color('gray')
                ->action(fn () => self::clearHiddenDates());
        }

        return $actions;
    }

    protected function getTableQuery(): Builder
    {
        $query = parent::getTableQuery();

        foreach (self::hiddenDates() as $date) {
            $query->whereDate('starts_at', '!=', $date);
        }

        return $query;
    }

    /**
     * @param  list<string>  $dates
     */
    public static function hideDates(array $dates): void
    {
        if ($dates === []) {
            return;
        }

        session([
            self::HIDDEN_DATES_SESSION_KEY => array_values(array_unique([
                ...self::hiddenDates(),
                ...$dates,
            ])),
        ]);
    }

    public static function clearHiddenDates(): void
    {
        session()->forget(self::HIDDEN_DATES_SESSION_KEY);
    }

    public static function hasHiddenDates(): bool
    {
        return self::hiddenDates() !== [];
    }

    /**
     * @return list<string>
     */
    public static function hiddenDates(): array
    {
        return array_values(session(self::HIDDEN_DATES_SESSION_KEY, []));
    }
}
