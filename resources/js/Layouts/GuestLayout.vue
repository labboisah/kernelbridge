<script setup>
import { Link } from '@inertiajs/vue3';
</script>

<template>
    <div
        class="flex min-h-screen flex-col items-center bg-[#F8FAFC] px-4 pt-8 sm:justify-center sm:pt-0"
    >
        <div
            class="w-full overflow-hidden border border-slate-200 bg-white px-6 py-6 shadow-lg sm:max-w-md sm:rounded-2xl"
        >
            <div class="mb-6 flex justify-center">
                <Link href="/" aria-label="KernelBridge Technologies home">
                    <img
                        src="/images/2.png"
                        alt="KernelBridge Technologies"
                        width="447"
                        height="431"
                        class="h-24 w-24 rounded-2xl object-cover"
                    />
                </Link>
            </div>

            <slot />
        </div>
    </div>
</template>
