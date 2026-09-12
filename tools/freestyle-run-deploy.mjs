import { Freestyle } from "freestyle";

const slug = process.env.FREESTYLE_VM_SLUG;
const domain = process.env.FREESTYLE_DOMAIN;
const wordpressPort = process.env.WORDPRESS_PORT;

for (const [name, value] of Object.entries({
  FREESTYLE_VM_SLUG: slug,
  FREESTYLE_DOMAIN: domain,
  WORDPRESS_PORT: wordpressPort,
})) {
  if (!value) throw new Error(`${name} is required`);
}

const shellQuote = (value) => `'${String(value).replace(/'/g, `'\\''`)}'`;
const freestyle = new Freestyle();
const vm = freestyle.vms.ref(slug);
const command = [
  "bash",
  "/tmp/deploy-meydan-backend.sh",
  shellQuote(domain),
  shellQuote(wordpressPort),
].join(" ");

const result = await vm.exec({
  command,
  linuxUser: "root",
  timeoutMs: 300_000,
});

if (result.stdout) process.stdout.write(result.stdout);
if (result.stderr) process.stderr.write(result.stderr);

if (result.statusCode !== 0) {
  throw new Error(`Freestyle deploy command failed with status ${result.statusCode}`);
}
