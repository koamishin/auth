@php
    $application = $applicationNavigation['application'];
    $applicationGroups = $applicationNavigation['groups'];
    $isSidebarCollapsibleOnDesktop = filament()->isSidebarCollapsibleOnDesktop();
@endphp

<div
    class="fi-app-nav"
    x-data="{
        groups: @js($applicationGroups),
        hash: window.location.hash,
        expanded: @js(array_keys($applicationGroups)),
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
        isOpen(index) {
            return (@js($isSidebarCollapsibleOnDesktop) && ! $store.sidebar.isOpen) || this.expanded.includes(index)
        },
        toggle(index) {
            this.expanded = this.expanded.includes(index)
                ? this.expanded.filter((value) => value !== index)
                : [...this.expanded, index]
        },
    }"
    x-on:hashchange.window="hash = window.location.hash; openActiveGroups()"
>
    <div class="fi-app-nav-context">
        <span class="fi-app-nav-name" title="{{ $application->name }}">{{ $application->name }}</span>
    </div>

    <div class="fi-app-nav-groups">
        @foreach ($applicationGroups as $applicationGroup)
            <section>
                <button
                    type="button"
                    class="fi-app-nav-group-button"
                    x-on:click="toggle({{ $loop->index }})"
                    x-bind:aria-expanded="isOpen({{ $loop->index }}).toString()"
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
                >
                    @php $groupIndex = $loop->index; @endphp
                    @foreach ($applicationGroup['items'] as $applicationItem)
                        <li>
                            <a
                                href="{{ $applicationItem['url'] }}{{ filled($applicationItem['fragment']) ? '#'.$applicationItem['fragment'] : '' }}"
                                class="fi-app-nav-link"
                                aria-label="{{ $applicationItem['label'] }}"
                                title="{{ $applicationItem['label'] }}"
                                x-on:click="$store.sidebar.isMobile && $store.sidebar.close()"
                                x-bind:class="{ 'fi-app-nav-link-active': isActive(groups[{{ $groupIndex }}].items[{{ $loop->index }}]) }"
                                x-bind:aria-current="isActive(groups[{{ $groupIndex }}].items[{{ $loop->index }}]) ? (groups[{{ $groupIndex }}].items[{{ $loop->index }}].fragment ? 'location' : 'page') : null"
                            >
                                <x-filament::icon :icon="$applicationItem['icon']" class="fi-app-nav-icon" />
                                <span class="fi-app-nav-link-label">{{ $applicationItem['label'] }}</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endforeach
    </div>
</div>
