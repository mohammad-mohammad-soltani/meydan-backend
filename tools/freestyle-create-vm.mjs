import { Freestyle } from "freestyle";

const slug = process.env.FREESTYLE_VM_SLUG;
if (!slug) throw new Error("FREESTYLE_VM_SLUG is required");

const freestyle = new Freestyle();

let existing = null;
try {
  existing = await freestyle.vms.get(slug);
} catch {
  existing = null;
}

if (existing) {
  const vm = freestyle.vms.ref(slug);
  await vm.update({ idleTimeoutSeconds: null });
  console.log(`Using existing Freestyle VM: ${slug}`);
  process.exit(0);
}

const { vmId } = await freestyle.vms.create({
  slug,
  idleTimeoutSeconds: null,
  firewall: {
    rules: [{ action: "allow", source: {}, destination: { public: true } }],
  },
  metadata: { project: "meydan-backend", managedBy: "github-actions" },
});

console.log(`Created Freestyle VM ${slug} (${vmId})`);
