<script setup lang="ts">
import { api, errorMessage } from '../../../src/api';

definePageMeta({ layout: 'default' });
useHead({ title: 'AI Assistant | AutoFlow' });

type Message = { role: 'user' | 'assistant'; content: string };
const messages = ref<Message[]>([
  { role: 'assistant', content: 'Hello! I am the local AutoFlow assistant. Ask me about vehicle leasing, financing or platform operations.' },
]);
const prompt = ref('');
const loading = ref(false);
const error = ref('');

async function send() {
  const content = prompt.value.trim();
  if (!content || loading.value) return;
  messages.value.push({ role: 'user', content });
  prompt.value = '';
  loading.value = true;
  error.value = '';
  try {
    const response = await api.post('/ai/api/v1/ai/chat', {
      messages: messages.value.map((message) => ({ role: message.role, content: message.content })),
    });
    const result = response.data?.data ?? response.data;
    const answer = result?.content ?? result?.message ?? result?.response ?? result?.choices?.[0]?.message?.content;
    messages.value.push({ role: 'assistant', content: typeof answer === 'string' ? answer : JSON.stringify(result, null, 2) });
  } catch (e) {
    error.value = errorMessage(e);
    messages.value.push({ role: 'assistant', content: 'I could not reach the local AI provider. Check the AI Service and Ollama model configuration.' });
  } finally {
    loading.value = false;
  }
}
function clear() {
  messages.value = [{ role: 'assistant', content: 'Conversation cleared. What would you like to know?' }];
  error.value = '';
}
</script>

<template>
  <div>
    <div class="d-flex flex-wrap align-center justify-space-between ga-3 mb-6">
      <div>
        <div class="text-overline text-medium-emphasis">AUTOFLOW · LOCAL AI</div>
        <h1 class="text-h4 font-weight-bold">AI Assistant</h1>
        <p class="text-body-2 text-medium-emphasis mt-2">Chat with the local Ollama-backed assistant for vehicle and mobility questions.</p>
      </div>
      <v-btn variant="tonal" prepend-icon="mdi-broom" @click="clear">Clear conversation</v-btn>
    </div>
    <v-alert type="warning" variant="tonal" class="mb-4">AI Service is documented as local-only and currently does not enforce JWT/tenant authorization. Keep this feature restricted to trusted local development networks; do not expose it publicly.</v-alert>
    <v-card rounded="xl" border elevation="0">
      <v-card-text class="chat-history">
        <div v-for="(message, index) in messages" :key="index" class="d-flex mb-4" :class="message.role === 'user' ? 'justify-end' : 'justify-start'">
          <v-card :color="message.role === 'user' ? 'primary' : 'surface-variant'" variant="tonal" rounded="lg" max-width="82%">
            <v-card-text class="text-body-2" style="white-space:pre-wrap">{{ message.content }}</v-card-text>
          </v-card>
        </div>
        <div v-if="loading" class="d-flex align-center ga-2 text-medium-emphasis"><v-progress-circular indeterminate size="18" width="2" /> Generating response…</div>
      </v-card-text>
      <v-divider />
      <v-card-text>
        <v-alert v-if="error" type="error" density="compact" variant="tonal" class="mb-3">{{ error }}</v-alert>
        <v-textarea v-model="prompt" label="Ask AutoFlow AI" placeholder="Explain leasing vs financing…" rows="2" auto-grow hide-details @keydown.ctrl.enter="send" />
        <div class="d-flex justify-end mt-3"><v-btn color="primary" append-icon="mdi-send" :disabled="!prompt.trim() || loading" :loading="loading" @click="send">Send message</v-btn></div>
      </v-card-text>
    </v-card>
  </div>
</template>

<style scoped>
.chat-history{min-height:360px;max-height:58vh;overflow-y:auto;padding:24px}
</style>
