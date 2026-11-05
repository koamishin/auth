<div class="py-2 -mx-4 -my-4 sm:-mx-6">
    @livewire(\App\Filament\Widgets\ApplicationQuickstartWidget::class, [
        'record' => $record,
    ], key('slideover-quickstart-' . $record->id . '-' . $record->environment . '-' . $record->updated_at?->format('U.u')))
</div>
