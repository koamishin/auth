@php
    $application = $applicationNavigation['application'];
    $applicationGroups = $application ? $applicationNavigation['groups'] : [];
@endphp

<div
    class="fi-app-nav"
    x-data="{
        groups: @js($applicationGroups),
        query: '',
        hash: window.location.hash,
        expanded: [],
        init() {
            this.openActiveGroups()
        },
        isActive(item) {
            if (! item.active) return false
            if (! item.fragment) return this.hash === ''

            return this.hash === '#' + item.fragment || this.hash === '#' + encodeURIComponent(item.fragment)
        },
        openActiveGroups() {
            this.groups.forEach((group, index) => {
                if (group.items.some((item) => this.isActive(item)) && ! this.expanded.includes(index)) {
                    this.expanded.push(index)
                }
            })
        },
        matches(value) {
            return value.toLocaleLowerCase().includes(this.query.trim().toLocaleLowerCase())
        },
        groupMatches(group) {
            return this.matches(group.label) || group.items.some((item) => this.matches(item.label))
        },
        itemMatches(group, item) {
            return this.matches(group.label) || this.matches(item.label)
        },
        isOpen(index) {
            return this.query.trim() !== '' || this.expanded.includes(index)
        },
        toggle(index) {
            if (this.query.trim() !== '') return
            this.expanded = this.expanded.includes(index)
                ? this.expanded.filter((value) => value !== index)
                : [...this.expanded, index]
        },
    }"
    x-on:hashchange.window="hash = window.location.hash; openActiveGroups()"
>
    @if ($application)
        <div class="fi-app-nav-context">
            <span class="fi-app-nav-caption">{{ __('Application') }}</span>
            <span class="fi-app-nav-name">{{ $application->name }}</span>
        </div>

        @if (count($applicationGroups))
            <label class="fi-app-nav-search">
                <x-filament::icon icon="heroicon-m-magnifying-glass" />
                <input
                    type="search"
                    x-model="query"
                    aria-label="{{ __('Search application navigation') }}"
                    placeholder="{{ __('Search settings') }}"
                    autocomplete="off"
                />
            </label>

            <div class="fi-app-nav-groups">
                @foreach ($applicationGroups as $applicationGroup)
                    <section x-show="groupMatches(groups[{{ $loop->index }}])" x-cloak>
                        <button
                            type="button"
                            class="fi-app-nav-group-button"
                            x-on:click="toggle({{ $loop->index }})"
                            x-bind:aria-expanded="isOpen({{ $loop->index }}).toString()"
                            x-bind:aria-disabled="query.trim() !== '' ? 'true' : null"
                            aria-controls="fi-app-nav-group-{{ $loop->index }}"
                        >
                            <span>{{ $applicationGroup['label'] }}</span>
                            <x-filament::icon
                                icon="heroicon-m-chevron-down"
                                x-bind:class="{ 'fi-app-nav-chevron-closed': ! isOpen({{ $loop->index }}) }"
                            />
                        </button>

                        <ul
                            id="fi-app-nav-group-{{ $loop->index }}"
                            class="fi-app-nav-items"
                            x-show="isOpen({{ $loop->index }})"
                            x-cloak
                        >
                            @php $groupIndex = $loop->index; @endphp
                            @foreach ($applicationGroup['items'] as $applicationItem)
                                <li x-show="itemMatches(groups[{{ $groupIndex }}], groups[{{ $groupIndex }}].items[{{ $loop->index }}])">
                                    <a
                                        href="{{ $applicationItem['url'] }}{{ filled($applicationItem['fragment']) ? '#'.$applicationItem['fragment'] : '' }}"
                                        class="fi-app-nav-link"
                                        x-on:click="$store.sidebar.isMobile && $store.sidebar.close()"
                                        x-bind:class="{ 'fi-app-nav-link-active': isActive(groups[{{ $groupIndex }}].items[{{ $loop->index }}]) }"
                                        x-bind:aria-current="isActive(groups[{{ $groupIndex }}].items[{{ $loop->index }}]) ? (groups[{{ $groupIndex }}].items[{{ $loop->index }}].fragment ? 'location' : 'page') : null"
                                    >{{ $applicationItem['label'] }}</a>
                                </li>
                            @endforeach
                        </ul>
                    </section>
                @endforeach
            </div>

            <p class="fi-app-nav-empty" x-show="query.trim() !== '' && ! groups.some((group) => groupMatches(group))" x-cloak>
                {{ __('No matching settings.') }}
            </p>
        @endif
    @else
        <p class="fi-app-nav-empty">{{ __('Select an application to see its settings.') }}</p>
    @endif
</div>
