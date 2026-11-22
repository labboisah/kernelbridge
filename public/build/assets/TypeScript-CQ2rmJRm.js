import{_ as n}from"./SeoHead-DmktNdur.js";import{_ as s}from"./CodeBlock-Chn5ma6Y.js";import{_ as o}from"./PublicLayout-ExEdho_l.js";import{o as r,c,a as i,w as a,d as t,F as l}from"./app-CYxyuONB.js";const p={class:"page-shell max-w-5xl py-16 lg:py-20"},d={class:"mt-10"},E={class:"mt-14"},m={class:"mt-14"},g={class:"mt-14"},f="npm install @kernelbridge/licensing-client-typescript better-sqlite3",v=`KERNELBRIDGE_API_URL=https://kernelbridge.com/api/v1
KERNELBRIDGE_PRODUCT_CODE=ALIGNEX
KERNELBRIDGE_API_TOKEN=replace-with-revocable-installation-token
KERNELBRIDGE_API_TIMEOUT_MS=10000
KERNELBRIDGE_VERIFICATION_INTERVAL_MS=900000
KERNELBRIDGE_OFFLINE_GRACE_MS=259200000
KERNELBRIDGE_CACHE_SIGNING_KEY=replace-with-random-secret
KERNELBRIDGE_APP_KEY=replace-with-stable-app-installation-scope
KERNELBRIDGE_RENEWAL_NOTICE_ENABLED=true
KERNELBRIDGE_RENEWAL_NOTICE_MONTHS=3,2,1`,u=`import Database from 'better-sqlite3';
import { app, safeStorage } from 'electron';
import {
  ElectronSafeStorageVault,
  KernelBridgeLicenseClient,
  LicenseActivationService,
  LicenseCacheService,
  LicenseGuard,
  LicenseVerificationService,
  PeriodicLicenseVerifier,
  SqliteLicenseStore,
  licensingOptionsFromEnv,
} from '@kernelbridge/licensing-client-typescript';

const options = { ...licensingOptionsFromEnv(process.env), appKey: app.getPath('userData') };
const store = new SqliteLicenseStore(new Database(licenseDatabasePath));
const vault = new ElectronSafeStorageVault(safeStorage);
const cache = new LicenseCacheService(store, vault, options);
const api = new KernelBridgeLicenseClient(options);

export const activation = new LicenseActivationService(api, cache, options);
export const verification = new LicenseVerificationService(api, cache, options);
export const license = new LicenseGuard(cache);

new PeriodicLicenseVerifier(() => verification.verify(true), options.verificationIntervalMs ?? 900000, console.error).start();`,L=`const decision = await license.checkActive();
if (!decision.allowed) {
  showActivationWindow(decision.message);
  return;
}

await openMainApplicationWindow();`,_=`export async function requireKernelBridgeLicense(_request, response, next) {
  const decision = await license.checkActive();
  if (!decision.allowed) {
    return response.status(402).json({ error: decision.reason, message: decision.message });
  }
  return next();
}`,K={__name:"TypeScript",setup(w){return(N,e)=>(r(),c(l,null,[i(n,{title:"TypeScript Licensing Client | KernelBridge",description:"Install and configure the KernelBridge TypeScript licensing client for Electron or Node.",canonical:"https://kernelbridge.com/docs/licensing/typescript"}),i(o,null,{default:a(()=>[e[10]||(e[10]=t("section",{class:"page-hero"},[t("div",{class:"page-shell"},[t("p",{class:"section-label !text-teal-300"},"TypeScript install guide"),t("h1",{class:"page-title"},"@kernelbridge/licensing-client-typescript"),t("p",{class:"page-intro"},"NPM client for Electron main processes and trusted Node services with encrypted SQLite state, activation decisions, feature guards, and renewal notices.")])],-1)),t("article",p,[e[9]||(e[9]=t("a",{href:"/docs/licensing",class:"font-bold text-cyan-700"},"Back to client SDKs",-1)),t("section",d,[e[0]||(e[0]=t("p",{class:"section-label"},"Install",-1)),e[1]||(e[1]=t("h2",{class:"section-title"},"Install the package in trusted runtime code.",-1)),e[2]||(e[2]=t("p",{class:"mt-5 leading-8 text-slate-700"},"Use this in Electron main process code or server-side Node. Do not expose tokens, license keys, or raw entitlements to browser renderers.",-1)),i(s,{title:"Terminal",code:f})]),t("section",E,[e[3]||(e[3]=t("p",{class:"section-label"},"Environment",-1)),e[4]||(e[4]=t("h2",{class:"section-title"},"Configure product credentials.",-1)),i(s,{title:".env",code:v})]),t("section",m,[e[5]||(e[5]=t("p",{class:"section-label"},"Services",-1)),e[6]||(e[6]=t("h2",{class:"section-title"},"Create one licensing module.",-1)),i(s,{title:"main/licensing.ts",code:u})]),t("section",g,[e[7]||(e[7]=t("p",{class:"section-label"},"Protection",-1)),e[8]||(e[8]=t("h2",{class:"section-title"},"Gate windows or server routes.",-1)),i(s,{title:"main/open-app.ts",code:L}),i(s,{title:"server/middleware.ts",code:_})])])]),_:1})],64))}};export{K as default};
