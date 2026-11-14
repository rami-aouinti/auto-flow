<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue';
type Notice = { id:string; title:string; detail:string; receivedAt:string; type:string };
const hubUrl = import.meta.env.VITE_MERCURE_HUB_URL || '';
const topics = (import.meta.env.VITE_MERCURE_TOPICS || '').split(',').map((value:string)=>value.trim()).filter(Boolean);
const connection = ref<'disabled'|'connecting'|'connected'|'reconnecting'>('disabled');
const notices = ref<Notice[]>([]);
const menu = ref(false);
let source: EventSource | null = null;
function connect(){
 if(!hubUrl || !topics.length || typeof window==='undefined'){connection.value='disabled';return;}
 connection.value='connecting';
 try{
  const url=new URL(hubUrl,window.location.origin);
  for(const topic of topics)url.searchParams.append('topic',topic);
  source=new EventSource(url.toString(),{withCredentials:true});
  source.onopen=()=>{connection.value='connected';};
  source.onmessage=(message)=>{
   let data:any={};try{data=JSON.parse(message.data);}catch{data={message:message.data};}
   const item:Notice={id:String(data.id??globalThis.crypto?.randomUUID?.()??Date.now()),title:String(data.type??data.eventType??'AutoFlow event'),detail:String(data.message??data.description??data.subject??message.data).slice(0,500),receivedAt:new Date().toISOString(),type:String(data.severity??data.level??'info')};
   notices.value=[item,...notices.value].slice(0,30);
   window.dispatchEvent(new CustomEvent('autoflow:realtime-event',{detail:item}));
  };
  source.onerror=()=>{connection.value='reconnecting';};
 }catch{connection.value='disabled';}
}
function clear(){notices.value=[];}
onMounted(connect);
onBeforeUnmount(()=>{source?.close();source=null;});
</script>
<template>
 <v-menu v-model="menu" :close-on-content-click="false" location="bottom end" width="380">
  <template #activator="{props}"><v-btn v-bind="props" icon variant="text" aria-label="Benachrichtigungen"><v-badge :content="notices.length" :model-value="notices.length>0" color="error"><v-icon icon="mdi-bell-outline"/></v-badge></v-btn></template>
  <v-card rounded="lg"><v-card-title class="d-flex align-center">Live-Benachrichtigungen<v-spacer/><v-chip size="x-small" :color="connection==='connected'?'success':connection==='disabled'?'default':'warning'" variant="tonal">{{connection==='connected'?'Mercure verbunden':connection==='disabled'?'Nicht konfiguriert':connection==='connecting'?'Verbindung …':'Verbindung wird wiederhergestellt'}}</v-chip></v-card-title>
   <v-card-subtitle v-if="connection==='disabled'">Setze VITE_MERCURE_HUB_URL und VITE_MERCURE_TOPICS. Ohne Hub bleiben die normalen API-Aktualisierungen verfügbar.</v-card-subtitle>
   <v-divider/><v-list v-if="notices.length" density="compact" max-height="360" class="overflow-y-auto"><v-list-item v-for="item in notices" :key="item.id" prepend-icon="mdi-bell-ring-outline" :title="item.title" :subtitle="item.detail"><template #append><span class="text-caption text-medium-emphasis">{{new Date(item.receivedAt).toLocaleTimeString()}}</span></template></v-list-item></v-list>
   <v-card-text v-else class="text-body-2 text-medium-emphasis">Noch keine neuen Ereignisse.</v-card-text><v-divider/><v-card-actions><v-btn variant="text" size="small" @click="clear">Leeren</v-btn><v-spacer/><v-btn variant="text" size="small" @click="menu=false">Schließen</v-btn></v-card-actions>
  </v-card>
 </v-menu>
</template>
