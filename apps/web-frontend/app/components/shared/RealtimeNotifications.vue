<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { customers } from '../../../src/api';

type Notice = { id: string; title: string; detail: string; receivedAt: string; type: string };
const hubUrl = import.meta.env.VITE_MERCURE_HUB_URL || '';
const connection = ref<'disabled' | 'connecting' | 'connected' | 'reconnecting'>('disabled');
const notices = ref<Notice[]>([]);
const menu = ref(false);
let source: EventSource | null = null;
let disposed = false;

async function connect() {
  if (!hubUrl || typeof window === 'undefined') {
    connection.value = 'disabled';
    return;
  }
  connection.value = 'connecting';
  try {
    // The API validates the Keycloak bearer token and sets a short-lived,
    // HttpOnly Mercure subscriber cookie scoped to the caller's tenant.
    const access = await customers.mercureAccess();
    if (disposed) return;
    const topic = access.data?.topic;
    if (typeof topic !== 'string' || !topic) throw new Error('Mercure topic is missing.');
    const url = new URL(hubUrl, window.location.origin);
    url.searchParams.append('topic', topic);
    source = new EventSource(url.toString(), { withCredentials: true });
    source.onopen = () => { connection.value = 'connected'; };
    source.onmessage = (message) => {
      let data: Record<string, unknown> = {};
      try {
        const parsed = JSON.parse(message.data);
        if (parsed && typeof parsed === 'object') data = parsed;
      } catch {
        data = { message: message.data };
      }
      const item: Notice = {
        id: String(data.id ?? globalThis.crypto?.randomUUID?.() ?? Date.now()),
        title: String(data.type ?? 'AutoFlow event'),
        detail: String(data.message ?? data.aggregateId ?? 'Business event received').slice(0, 300),
        receivedAt: String(data.occurredAt ?? new Date().toISOString()),
        type: String(data.severity ?? 'info'),
      };
      notices.value = [item, ...notices.value.filter((notice) => notice.id !== item.id)].slice(0, 30);
      window.dispatchEvent(new CustomEvent('autoflow:realtime-event', { detail: item }));
    };
    source.onerror = () => { connection.value = 'reconnecting'; };
  } catch {
    connection.value = 'disabled';
  }
}
function clear() {
  notices.value = [];
}
onMounted(() => { void connect(); });
onBeforeUnmount(() => {
  disposed = true;
  source?.close();
  source = null;
});
</script>

<template>
  <v-menu v-model="menu" :close-on-content-click="false" location="bottom end" width="380">
    <template #activator="{ props }">
      <v-btn v-bind="props" icon variant="text" aria-label="Benachrichtigungen">
        <v-badge :content="notices.length" :model-value="notices.length > 0" color="error"><v-icon icon="mdi-bell-outline" /></v-badge>
      </v-btn>
    </template>
    <v-card rounded="lg">
      <v-card-title class="d-flex align-center">
        Live-Benachrichtigungen
        <v-spacer />
        <v-chip size="x-small" :color="connection === 'connected' ? 'success' : connection === 'disabled' ? 'default' : 'warning'" variant="tonal">
          {{ connection === 'connected' ? 'Mercure verbunden' : connection === 'disabled' ? 'Nicht verfügbar' : connection === 'connecting' ? 'Verbindung …' : 'Verbindung wird wiederhergestellt' }}
        </v-chip>
      </v-card-title>
      <v-card-subtitle v-if="connection === 'disabled'">Keine tenant-gebundene Live-Verbindung verfügbar. Die normalen API-Aktualisierungen funktionieren weiterhin.</v-card-subtitle>
      <v-divider />
      <v-list v-if="notices.length" density="compact" max-height="360" class="overflow-y-auto">
        <v-list-item v-for="item in notices" :key="item.id" prepend-icon="mdi-bell-ring-outline" :title="item.title" :subtitle="item.detail">
          <template #append><span class="text-caption text-medium-emphasis">{{ new Date(item.receivedAt).toLocaleTimeString() }}</span></template>
        </v-list-item>
      </v-list>
      <v-card-text v-else class="text-body-2 text-medium-emphasis">Noch keine neuen Ereignisse.</v-card-text>
      <v-divider />
      <v-card-actions><v-btn variant="text" size="small" @click="clear">Leeren</v-btn><v-spacer /><v-btn variant="text" size="small" @click="menu = false">Schließen</v-btn></v-card-actions>
    </v-card>
  </v-menu>
</template>
