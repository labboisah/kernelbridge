export type ClientRuntime = 'laravel' | 'typescript' | 'spring-boot' | 'django';

export interface ToolResponse {
  [key: string]: unknown;
  content: Array<{ type: 'text'; text: string }>;
}

export interface SetupTemplateInput {
  runtime: ClientRuntime;
  productCode: string;
  apiUrl: string;
  apiToken?: string | undefined;
  cacheSigningKey?: string | undefined;
}

export interface SetupTemplate {
  runtime: ClientRuntime;
  packageName: string;
  install: string;
  configuration: string;
  protection: string;
  activationPath: string;
  docsPath: string;
}

export interface ToolHandler<TInput extends Record<string, unknown> = Record<string, unknown>> {
  name: string;
  description: string;
  mutation: boolean;
  useCase: 'admin_support' | 'onboarding' | 'license_operations' | 'documentation' | 'assistant_workflows';
  handler(input: TInput): Promise<ToolResponse>;
}

export function textResponse(value: unknown): ToolResponse {
  const text = typeof value === 'string' ? value : JSON.stringify(value, null, 2);
  return { content: [{ type: 'text' as const, text }] };
}


