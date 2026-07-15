<script setup lang="ts">
import { computed, ref } from "vue";
import { testingUrl } from "../../api";
import ChecklistShell from "../../components/ChecklistShell.vue";
import ChecklistStatusTag from "../../components/ChecklistStatusTag.vue";
import RolePopover from "../../components/RolePopover.vue";
import { checklistTextPlain } from "../../utils/checklistText";
import type { RoleInfo, SuiteSummary, TesterCredential } from "../../types";
import YIcon from "../../ui/YIcon.vue";
import YMessage from "../../ui/YMessage.vue";

const props = defineProps<{
    tester: {
        slug: string;
        name: string;
        credentials: TesterCredential[];
    };
    roles: RoleInfo[];
    roleReferenceUrl: string | null;
    suites: {
        slug: string;
        title: string;
        description: string | null;
        summary: SuiteSummary;
    }[];
}>();

/**
 * When every credential shares one password, a banner states it once and the
 * Password column disappears; distinct passwords fall back to the column.
 */
const sharedPassword = computed(() => {
    const passwords = new Set(props.tester.credentials.map((credential) => credential.password));

    return passwords.size === 1 ? (props.tester.credentials[0]?.password ?? null) : null;
});

const rolePopover = ref<InstanceType<typeof RolePopover> | null>(null);

function roleFor(credential: TesterCredential): RoleInfo | null {
    return props.roles.find((role) => role.key === credential.role) ?? null;
}
</script>

<template>
    <ChecklistShell
        :title="`Checklists — ${tester.name}`"
        :crumbs="[{ label: 'Testers', href: testingUrl() }, { label: tester.name }]"
    >
        <h1 class="text-3xl font-bold tracking-tight text-surface-900 dark:text-surface-0">
            {{ tester.name }}
        </h1>

        <section class="mt-6">
            <h2 class="text-sm font-semibold text-surface-900 dark:text-surface-0">Your test logins</h2>
            <p class="mt-1 text-xs text-surface-500 dark:text-surface-400">
                Each login is a separate individual — the email tells you which permissions that session should
                have. Use the one each checklist asks for. Hover or tap a role to see what it can do; click an
                email to open a session as that login, no password needed.
            </p>
            <YMessage v-if="sharedPassword" severity="info" class="mt-3">
                The password for all users is
                <code class="font-mono font-semibold">{{ sharedPassword }}</code
                >.
            </YMessage>
            <div
                class="mt-3 overflow-x-auto rounded-md border border-surface-200 bg-surface-0 shadow-sm dark:border-surface-700 dark:bg-surface-800"
            >
                <table class="w-full text-left text-sm">
                    <thead>
                        <tr
                            class="border-b border-surface-200 text-xs text-surface-500 uppercase dark:border-surface-700 dark:text-surface-400"
                        >
                            <th class="px-4 py-2 font-semibold">Login</th>
                            <th class="px-4 py-2 font-semibold">Email</th>
                            <th v-if="!sharedPassword" class="px-4 py-2 font-semibold">Password</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="credential in tester.credentials"
                            :key="credential.email"
                            class="border-b border-surface-100 last:border-0 dark:border-surface-700/50"
                        >
                            <td class="px-4 py-2 text-surface-700 dark:text-surface-300">
                                <button
                                    v-if="roleFor(credential)"
                                    type="button"
                                    class="cursor-help text-left underline decoration-surface-400 decoration-dotted underline-offset-4 hover:text-primary-600 hover:decoration-primary-400 dark:decoration-surface-500 dark:hover:text-primary-400"
                                    :aria-label="`Show what the ${roleFor(credential)!.name} role can do`"
                                    @mouseenter="rolePopover?.showFor($event, roleFor(credential)!)"
                                    @mouseleave="rolePopover?.scheduleHide()"
                                    @click="rolePopover?.toggleFor($event, roleFor(credential)!)"
                                >
                                    {{ credential.label }}
                                </button>
                                <template v-else>{{ credential.label }}</template>
                                <p v-if="credential.note" class="text-xs text-surface-500 dark:text-surface-400">
                                    {{ credential.note }}
                                </p>
                            </td>
                            <td class="px-4 py-2 font-mono text-xs">
                                <a
                                    v-if="credential.login_url"
                                    :href="credential.login_url"
                                    target="_blank"
                                    class="text-primary-600 hover:underline dark:text-primary-400"
                                    :title="`Open a session as ${credential.email}`"
                                >
                                    {{ credential.email }}
                                    <YIcon name="external-link" />
                                </a>
                                <span v-else class="text-surface-900 dark:text-surface-0">
                                    {{ credential.email }}
                                </span>
                            </td>
                            <td v-if="!sharedPassword" class="px-4 py-2 font-mono text-xs text-surface-900 dark:text-surface-0">
                                {{ credential.password }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="mt-8">
            <h2 class="text-sm font-semibold text-surface-900 dark:text-surface-0">Checklists</h2>
            <div class="mt-3 space-y-3">
                <a
                    v-for="suite in suites"
                    :key="suite.slug"
                    :href="testingUrl(tester.slug, suite.slug)"
                    class="flex items-center justify-between gap-4 rounded-md border border-surface-200 bg-surface-0 p-4 shadow-sm transition hover:border-primary-400 dark:border-surface-700 dark:bg-surface-800 dark:hover:border-primary-400"
                >
                    <div class="min-w-0">
                        <p class="font-semibold text-surface-900 dark:text-surface-0">
                            {{ suite.title }}
                        </p>
                        <p v-if="suite.description" class="mt-0.5 truncate text-sm text-surface-500 dark:text-surface-400">
                            {{ checklistTextPlain(suite.description) }}
                        </p>
                    </div>
                    <ChecklistStatusTag :status="suite.summary.status" />
                </a>
            </div>
            <p v-if="suites.length === 0" class="mt-3 text-sm text-surface-500 dark:text-surface-400">
                No checklists defined yet.
            </p>
        </section>

        <RolePopover ref="rolePopover" :reference-url="roleReferenceUrl" />
    </ChecklistShell>
</template>
