import { readFile } from 'node:fs/promises';
import { resolve } from 'node:path';

export interface DocumentationResource {
  name: string;
  uri: string;
  relativePath: string;
  description: string;
}

export const documentationResources: DocumentationResource[] = [
  { name: 'Licensing Client Spec', uri: 'kernelbridge://docs/licensing/spec', relativePath: 'docs/LICENSING_CLIENT_SPEC.md', description: 'Shared behavior required for every KernelBridge licensing client.' },
  { name: 'Laravel Licensing Client', uri: 'kernelbridge://docs/licensing/laravel', relativePath: 'docs/LICENSING_CLIENT.md', description: 'Laravel package developer documentation.' },
  { name: 'TypeScript Licensing Client', uri: 'kernelbridge://docs/licensing/typescript', relativePath: 'docs/TYPESCRIPT_LICENSING_CLIENT.md', description: 'TypeScript package developer documentation.' },
  { name: 'Spring Boot Licensing Client', uri: 'kernelbridge://docs/licensing/spring-boot', relativePath: 'docs/SPRING_BOOT_LICENSING_CLIENT.md', description: 'Spring Boot starter developer documentation.' },
  { name: 'Django Licensing Client', uri: 'kernelbridge://docs/licensing/django', relativePath: 'docs/DJANGO_LICENSING_CLIENT.md', description: 'Django package developer documentation.' },
  { name: 'Product Integration API', uri: 'kernelbridge://docs/api/v1', relativePath: 'docs/api-v1-openapi.yaml', description: 'OpenAPI contract for product integrations.' },
  { name: 'Multi Product Infrastructure', uri: 'kernelbridge://docs/infrastructure/multi-product', relativePath: 'docs/MULTI_PRODUCT_INFRASTRUCTURE.md', description: 'Architecture guidance for commercial product integrations.' },
];

export function listDocumentationResources(): DocumentationResource[] {
  return documentationResources;
}

export async function readDocumentationResource(docsRoot: string, uri: string): Promise<string> {
  const resource = documentationResources.find((item) => item.uri === uri || item.name === uri || item.relativePath === uri);
  if (!resource) throw new Error(`Unknown KernelBridge documentation resource: ${uri}`);
  const root = resolve(docsRoot);
  const path = resolve(root, resource.relativePath);
  if (!path.startsWith(root)) throw new Error('Refusing to read documentation outside KERNELBRIDGE_DOCS_ROOT.');
  return readFile(path, 'utf8');
}
