export interface KernelBridgeMcpConfig {
  apiUrl: string;
  apiToken: string;
  docsRoot: string;
  defaultProductCode?: string;
  allowMutations: boolean;
}

export function configFromEnv(env: NodeJS.ProcessEnv = process.env): KernelBridgeMcpConfig {
  return {
    apiUrl: (env.KERNELBRIDGE_API_URL ?? 'https://kernelbridge.com/api/v1').replace(/\/$/, ''),
    apiToken: env.KERNELBRIDGE_API_TOKEN ?? '',
    docsRoot: env.KERNELBRIDGE_DOCS_ROOT ?? process.cwd(),
    ...(env.KERNELBRIDGE_PRODUCT_CODE ? { defaultProductCode: env.KERNELBRIDGE_PRODUCT_CODE } : {}),
    allowMutations: env.KERNELBRIDGE_MCP_ALLOW_MUTATIONS === 'true',
  };
}

export function requireApiToken(config: KernelBridgeMcpConfig): void {
  if (!config.apiToken) throw new Error('KERNELBRIDGE_API_TOKEN is required for KernelBridge data tools.');
}

