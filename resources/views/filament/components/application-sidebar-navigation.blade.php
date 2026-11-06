@php
    use App\Support\ApplicationNavigation;

    $application = $applicationNavigation['application'];
    $applicationGroups = ApplicationNavigation::navigationGroups($applicationNavigation['groups']);
@endphp

{{-- Rendered with Filament's own sidebar components so this section is visually
     identical to the workspace navigation above it. --}}
<div
    class="fi-app-nav"
    x-data="{
        hash: window.location.hash,
        isActive(routeActive, fragment) {
            if (! routeActive) {
                return false
            }

            return fragment ? this.hash === '#' + fragment : this.hash === ''
        },
    }"
    x-on:hashchange.window="hash = window.location.hash"
>
    <div class="fi-app-nav-context">
        <span class="fi-sidebar-group-label">{{ $application->name }}</span>
    </div>

    <ul class="fi-sidebar-nav-groups">
        @foreach ($applicationGroups as $applicationGroup)
            <x-filament-panels::sidebar.group
                :active="$applicationGroup->isActive()"
                :collapsible="$applicationGroup->isCollapsible()"
                :items="$applicationGroup->getItems()"
                :label="$applicationGroup->getLabel()"
                :attributes="\Filament\Support\prepare_inherited_attributes($applicationGroup->getExtraSidebarAttributeBag())"
            />
        @endforeach
    </ul>
</div>
