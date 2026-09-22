{{-- The public footer: copyright on the left, the contact buttons,
     address and hours on the right. In edit mode the copyright line
     carries the footer's Edit button. --}}
    @php
        $settings = \App\Models\SiteSettings::current();
        $editing = $editing ?? false;
        // The homepage's own contact buttons, edited on the page. The
        // Contact rows under Settings > Contact are a separate list that
        // feeds the floating buttons on the logged-in pages.
        $contacts = \App\Support\HomepageContent::get('footer')['contacts'] ?? [];
    @endphp
    <footer id="contact" class="scroll-mt-20 border-t border-orange-100 bg-white">
        <div class="flex flex-col gap-4 px-5 py-6 text-sm text-slate-700 sm:flex-row sm:items-center sm:justify-between sm:px-8 lg:px-14 xl:px-24">
            <p class="flex items-center gap-3 text-slate-600 sm:shrink-0">
                <span>{{ \App\Support\HomepageContent::fill(\App\Support\HomepageContent::get('footer')['copyright']) }}</span>
                @if ($editing)
                    <button type="button" @click="$dispatch('homepage-edit', 'footer')" aria-label="Edit footer"
                            class="inline-flex items-center gap-1 rounded-full bg-slate-900 px-2.5 py-1 text-xs font-semibold text-white shadow hover:bg-orange-600">
                        <svg class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L6.832 19.82a4.5 4.5 0 01-1.897 1.13l-2.685.8.8-2.685a4.5 4.5 0 011.13-1.897L16.863 4.487z"/></svg>
                        Edit
                    </button>
                @endif
            </p>
            <div class="flex flex-wrap items-center gap-x-6 gap-y-2 sm:justify-end">
                @foreach ($contacts as $c)
                    @php
                        $type = (string) ($c['type'] ?? '');
                        $url = \App\Models\Contact::linkFor($type, $c['value'] ?? '');
                        $text = ($c['label'] ?? '') !== '' ? $c['label'] : ($c['value'] ?? '');
                        $typeLabel = \App\Models\Contact::labelFor($type);
                        $external = $type !== \App\Models\Contact::TYPE_PHONE;
                        $uploadedIcon = ($c['icon'] ?? '') !== '' ? \App\Support\PublicFile::url($c['icon']) : null;
                        $builtInIcon = \App\Models\Contact::builtInIconUrl($type);
                    @endphp
                    @if ($url)
                        <a href="{{ $url }}"
                           @if ($external) target="_blank" rel="noopener" @endif
                           class="inline-flex items-center gap-2 hover:text-orange-600"
                           title="{{ $typeLabel }}: {{ $c['value'] ?? '' }}">
                    @else
                        <span class="inline-flex items-center gap-2" title="{{ $typeLabel }}">
                    @endif
                            @if ($uploadedIcon)
                                <img src="{{ $uploadedIcon }}" alt="" class="h-6 w-6 rounded-full object-cover" data-contact-icon="uploaded" />
                            @elseif ($builtInIcon)
                                <img src="{{ $builtInIcon }}" alt="" class="h-6 w-6 object-contain" data-contact-icon="built-in" />
                            @elseif ($type === \App\Models\Contact::TYPE_FACEBOOK)
                                <span class="inline-flex h-6 w-6 items-center justify-center rounded-full bg-[#1877F2] text-white" aria-hidden="true">
                                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="currentColor"><path d="''' + FB_PATH + '''"/></svg>
                                </span>
                            @elseif ($type === \App\Models\Contact::TYPE_XHS)
                                <span class="inline-flex h-6 w-6 items-center justify-center rounded-full bg-[#FF2442] text-[7px] font-extrabold text-white" aria-hidden="true">XHS</span>
                            @elseif ($type === \App\Models\Contact::TYPE_WHATSAPP)
                                <span class="inline-flex h-6 w-6 items-center justify-center rounded-full bg-[#25D366] text-white" aria-hidden="true">
                                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8.625 9.75a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H8.25m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H12m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0h-.375m-13.5 3.01c0 1.6 1.123 2.994 2.707 3.227 1.087.16 2.185.283 3.293.369V21l4.184-4.183a1.14 1.14 0 01.778-.332 48.294 48.294 0 005.83-.498c1.585-.233 2.708-1.626 2.708-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0012 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741v6.018z"/></svg>
                                </span>
                            @elseif ($type === \App\Models\Contact::TYPE_TELEGRAM)
                                <span class="inline-flex h-6 w-6 items-center justify-center rounded-full bg-[#2AABEE] text-white" aria-hidden="true">
                                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 12L3.269 3.126A59.768 59.768 0 0121.485 12 59.77 59.77 0 013.27 20.876L5.999 12zm0 0h7.5"/></svg>
                                </span>
                            @else
                                <span class="inline-flex h-6 w-6 items-center justify-center rounded-full bg-slate-900 text-white" aria-hidden="true">
                                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 002.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 01-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 00-1.091-.852H4.5A2.25 2.25 0 002.25 4.5v2.25z"/></svg>
                                </span>
                            @endif
                            <span>{{ $text }}</span>
                    @if ($url)
                        </a>
                    @else
                        </span>
                    @endif
                @endforeach

                @if ($settings->contact_address)
                    <span class="inline-flex items-center gap-2" title="Address">
                        <span class="inline-flex h-6 w-6 items-center justify-center rounded-full bg-orange-500 text-white" aria-hidden="true">
                            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z"/></svg>
                        </span>
                        <span class="whitespace-pre-line">{{ $settings->contact_address }}</span>
                    </span>
                @endif
                @if ($settings->contact_hours)
                    <span class="inline-flex items-center gap-2" title="Hours">
                        <span class="inline-flex h-6 w-6 items-center justify-center rounded-full bg-orange-500 text-white" aria-hidden="true">
                            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        </span>
                        <span>{{ $settings->contact_hours }}</span>
                    </span>
                @endif
            </div>
        </div>
    </footer>
