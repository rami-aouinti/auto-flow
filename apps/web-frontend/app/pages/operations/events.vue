<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { billing, contracts, fulfillments, inventory, offers, orders, reservations, errorMessage } from '../../../src/api';
definePageMeta({layout:'default'});
useHead({title:'Events & Fehler | AutoFlow'});
type Row=Record<string,any>;
type Activity={id:string;source:string;event:string;occurredAt:string;reference:string;payload?:unknown;severity?:string};
const events=ref<Activity[]>([]);const errors=ref<{source:string;message:string;at:string}[]>([]);const loading=ref(false);const lastUpdated=ref('');
let timer:ReturnType<typeof setInterval>|undefined;
function list(data:any):Row[]{return Array.isArray(data)?data:Array.isArray(data?.items)?data.items:[];}
function get(row:Row,...keys:string[]){for(const key of keys){const camel=key.replace(/_([a-z])/g,(_,c:string)=>c.toUpperCase());if(row[key]!==undefined)return row[key];if(row[camel]!==undefined)return row[camel];}return undefined;}
function addRows(source:string,rows:Row[],idKeys:string[],eventKeys:string[],dateKeys:string[]):Activity[]{return rows.map((row,index)=>({id:String(get(row,...idKeys)??index),source,event:String(get(row,...eventKeys)??get(row,'status')??'Record updated'),occurredAt:String(get(row,...dateKeys)??new Date().toISOString()),reference:String(get(row,'order_id','contract_id','fulfillment_id','inventory_unit_id','reservation_id','invoice_id','offer_id')??get(row,'id')??'—'),payload:row}));}
async function refresh(){
 loading.value=true;errors.value=[];
 const jobs=await Promise.allSettled([
  orders.list(30,0),contracts.list(30,0),fulfillments.list(30,0),inventory.units(30,0),reservations.list(30,0),billing.invoices(30,0),offers.list({limit:30,offset:0}),
 ]);
 const names=['Orders','Contracts','Fulfillment','Inventory','Reservations','Billing','Offers'];
 const output:Activity[]=[];
 jobs.forEach((job,index)=>{
  if(job.status==='rejected'){errors.value.push({source:names[index]??'Service',message:errorMessage(job.reason),at:new Date().toISOString()});return;}
  const rows=list(job.value.data);
  const mapping=[
   ['order_id',['event_type','to_status','status'],['occurred_at','updated_at','created_at']],
   ['contract_id',['event_type','status'],['occurred_at','updated_at','created_at']],
   ['fulfillment_id',['event_type','status'],['occurred_at','updated_at','created_at']],
   ['inventory_unit_id',['event_type','status'],['occurred_at','updated_at','created_at']],
   ['reservation_id',['event_type','status'],['occurred_at','updated_at','created_at']],
   ['invoice_id',['event_type','status'],['issued_at','created_at','updated_at']],
   ['offer_id',['event_type','status'],['updated_at','created_at']],
  ][index] as [string,string[],string[]];
  output.push(...addRows(names[index]??'Service',rows,[mapping[0]],mapping[1],mapping[2]));
 });
 events.value=output.sort((a,b)=>new Date(b.occurredAt).getTime()-new Date(a.occurredAt).getTime()).slice(0,150);
 lastUpdated.value=new Date().toLocaleString('de-DE');loading.value=false;
}
function onRealtime(event:Event){const item=(event as CustomEvent).detail as Activity;if(item)events.value=[{id:item.id,source:'Mercure',event:item.title,occurredAt:item.receivedAt,reference:item.detail,payload:item},...events.value].slice(0,150);}
onMounted(()=>{void refresh();timer=setInterval(()=>void refresh(),30000);window.addEventListener('autoflow:realtime-event',onRealtime);});
onBeforeUnmount(()=>{if(timer)clearInterval(timer);window.removeEventListener('autoflow:realtime-event',onRealtime);});
</script>
<template>
 <div>
  <div class="d-flex flex-wrap align-center justify-space-between ga-3 mb-5"><div><div class="text-overline text-medium-emphasis">AUTOFLOW · OBSERVABILITY</div><h1 class="text-h4 font-weight-bold">Business Events & Errors</h1><p class="text-body-2 text-medium-emphasis">Aktuelle Zustände aus den Business-Services, API-Fehler und Live-Ereignisse aus Mercure.</p></div><v-btn prepend-icon="mdi-refresh" variant="tonal" :loading="loading" @click="refresh">Jetzt aktualisieren</v-btn></div>
  <v-row class="mb-3"><v-col cols="12" md="4"><v-card rounded="xl" border elevation="0"><v-card-text><div class="text-caption text-medium-emphasis">Aktivitäten</div><div class="text-h4 font-weight-bold">{{events.length}}</div></v-card-text></v-card></v-col><v-col cols="12" md="4"><v-card rounded="xl" border elevation="0"><v-card-text><div class="text-caption text-medium-emphasis">Servicefehler beim Abruf</div><div class="text-h4 font-weight-bold" :class="errors.length?'text-error':''">{{errors.length}}</div></v-card-text></v-card></v-col><v-col cols="12" md="4"><v-card rounded="xl" border elevation="0"><v-card-text><div class="text-caption text-medium-emphasis">Letzte Synchronisierung</div><div class="text-body-1 font-weight-bold">{{lastUpdated||'—'}}</div></v-card-text></v-card></v-col></v-row>
  <v-alert v-if="errors.length" type="error" variant="tonal" class="mb-4" title="Einige Services konnten nicht gelesen werden"><div v-for="item in errors" :key="item.source" class="text-body-2"><strong>{{item.source}}:</strong> {{item.message}}</div></v-alert>
  <v-card rounded="xl" border elevation="0"><v-card-title>Business Activity Feed <v-spacer/><span class="text-caption text-medium-emphasis">Auto-Refresh alle 30 Sekunden</span></v-card-title><v-divider/><v-progress-linear v-if="loading" indeterminate color="primary"/><v-table><thead><tr><th>Quelle</th><th>Ereignis / Status</th><th>Referenz</th><th>Zeitpunkt</th></tr></thead><tbody><tr v-for="item in events" :key="item.source+item.id"><td><v-chip size="small" variant="tonal">{{item.source}}</v-chip></td><td>{{item.event}}</td><td class="text-caption">{{item.reference}}</td><td class="text-caption">{{new Date(item.occurredAt).toLocaleString('de-DE')}}</td></tr><tr v-if="!events.length"><td colspan="4" class="text-center text-medium-emphasis py-8">Noch keine Aktivitäten vorhanden.</td></tr></tbody></v-table></v-card>
  <p class="text-caption text-medium-emphasis mt-3">Hinweis: Dieser Feed aggregiert lesbare Service-Datensätze und Statuswerte. Vollständige technische Logs und Traces bleiben in Grafana/Loki/Tempo; Ereignisse in Echtzeit erscheinen zusätzlich, sobald Mercure-Hub und Topics konfiguriert sind.</p>
 </div>
</template>
