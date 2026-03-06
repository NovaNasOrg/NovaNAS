import { useState, useEffect } from 'react';
import {
    Box,
    Title,
    Text,
    Group,
    Button,
    Modal,
    TextInput,
    Select,
    Stack,
    Switch,
    Badge,
    Loader,
    Alert,
    ActionIcon,
    Table,
    useMantineTheme,
} from '@mantine/core';
import { useDisclosure } from '@mantine/hooks';
import {
    IconPlus,
    IconTrash,
    IconEdit,
    IconRefresh,
    IconSearch,
    IconWifi,
} from '@tabler/icons-react';

export function UpnpTab() {
    const theme = useMantineTheme();
    const [rules, setRules] = useState([]);
    const [interfaces, setInterfaces] = useState([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [opened, { open: openModal, close: closeModal }] = useDisclosure(false);
    const [editingRule, setEditingRule] = useState(null);
    const [deleteConfirm, setDeleteConfirm] = useState(null);
    const [discovering, setDiscovering] = useState(false);
    const [discoverResult, setDiscoverResult] = useState(null);
    const [publishing, setPublishing] = useState(false);
    const [submitting, setSubmitting] = useState(false);

    const [formData, setFormData] = useState({
        name: '',
        interface: '',
        external_port: '',
        internal_port: '',
        protocol: 'TCP',
        description: '',
        is_enabled: true,
    });

    const [modalError, setModalError] = useState(null);

    useEffect(() => {
        fetchRules();
        fetchInterfaces();
    }, []);

    const fetchRules = async () => {
        try {
            setLoading(true);
            const response = await fetch('/api/upnp/rules');
            const data = await response.json();

            setRules(data.rules || []);
            setError(null);
        } catch (err) {
            setError('Failed to load UPNP rules');
            console.error(err);
        } finally {
            setLoading(false);
        }
    };

    const fetchInterfaces = async () => {
        try {
            const response = await fetch('/api/upnp/interfaces');
            const data = await response.json();
            setInterfaces(data || []);
        } catch (err) {
            console.error('Failed to load interfaces:', err);
        }
    };

    const handleDiscover = async () => {
        setDiscovering(true);
        setDiscoverResult(null);

        try {
            const response = await fetch('/api/upnp/discover');
            const data = await response.json();
            setDiscoverResult(data);
        } catch (err) {
            setDiscoverResult({
                found: false,
                message: 'Failed to discover UPNP devices',
            });
        } finally {
            setDiscovering(false);
        }
    };

    const handlePublishAll = async () => {
        setPublishing(true);

        try {
            const response = await fetch('/api/upnp/publish-all', {
                method: 'POST',
            });
            const data = await response.json();

            setError(null);
            alert(data.message);
            await fetchRules();
        } catch (err) {
            setError('Failed to publish all rules');
        } finally {
            setPublishing(false);
        }
    };

    const handleSubmit = async (e) => {
        e.preventDefault();
        setModalError(null);
        setSubmitting(true);

        try {
            const submitData = {
                ...formData,
                external_port: parseInt(formData.external_port, 10),
                internal_port: parseInt(formData.internal_port, 10),
            };

            let url = '/api/upnp/rules';
            const method = editingRule ? 'PUT' : 'POST';

            if (editingRule) {
                url = `/api/upnp/rules/${editingRule.id}`;
            }

            const response = await fetch(url, {
                method,
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(submitData),
            });

            const data = await response.json();

            if (!response.ok) {
                throw new Error(data.message || 'Failed to save rule');
            }

            await fetchRules();
            closeModal();
            resetForm();
        } catch (err) {
            setModalError(err.message);
        } finally {
            setSubmitting(false);
        }
    };

    const handleDelete = async (id) => {
        try {
            const response = await fetch(`/api/upnp/rules/${id}`, {
                method: 'DELETE',
            });

            if (!response.ok) {
                const data = await response.json();
                throw new Error(data.message || 'Delete failed');
            }

            await fetchRules();
            setDeleteConfirm(null);
        } catch (err) {
            setError(err.message);
        }
    };

    const openEditModal = (rule) => {
        setEditingRule(rule);
        setModalError(null);
        setFormData({
            name: rule.name,
            interface: rule.interface,
            external_port: rule.external_port.toString(),
            internal_port: rule.internal_port.toString(),
            protocol: rule.protocol,
            description: rule.description || '',
            is_enabled: rule.is_enabled,
        });
        openModal();
    };

    const resetForm = () => {
        setEditingRule(null);
        setModalError(null);
        setFormData({
            name: '',
            interface: '',
            external_port: '',
            internal_port: '',
            protocol: 'TCP',
            description: '',
            is_enabled: true,
        });
    };

    const openCreateModal = () => {
        resetForm();
        openModal();
    };

    const interfaceOptions = interfaces.map((iface) => ({
        value: iface.name,
        label: `${iface.name} (${iface.ipv4 || 'No IP'})`,
    }));

    if (loading) {
        return (
            <Box style={{ display: 'flex', justifyContent: 'center', alignItems: 'center', height: '100%' }}>
                <Loader size="lg" />
            </Box>
        );
    }

    return (
        <Box>
            <Group justify="space-between" mb="lg">
                <div>
                    <Title order={3} c="white">UPNP</Title>
                    <Text size="sm" c="dimmed">Manage UPnP port forwarding rules</Text>
                </div>
                <Group gap="sm">
                    <Button
                        variant="light"
                        leftSection={discovering ? <Loader size={16} /> : <IconSearch size={16} />}
                        onClick={handleDiscover}
                        loading={discovering}
                    >
                        Discover Routers
                    </Button>
                    <Button
                        leftSection={<IconRefresh size={16} />}
                        onClick={handlePublishAll}
                        loading={publishing}
                    >
                        Republish All
                    </Button>
                    <Button
                        leftSection={<IconPlus size={16} />}
                        onClick={openCreateModal}
                    >
                        Add Rule
                    </Button>
                </Group>
            </Group>

            {discoverResult && (
                <Alert
                    color={discoverResult.found ? 'green' : 'yellow'}
                    variant="light"
                    mb="md"
                    icon={<IconWifi size={16} />}
                >
                    <Text fw={500}>{discoverResult.message}</Text>
                    {discoverResult.found && (
                        <Text size="sm">
                            LAN: {discoverResult.lan_address} | External IP: {discoverResult.external_ip}
                        </Text>
                    )}
                </Alert>
            )}

            {error && (
                <Alert
                    color="red"
                    variant="light"
                    mb="md"
                    onClose={() => setError(null)}
                    withCloseButton
                >
                    {error}
                </Alert>
            )}

            {rules.length === 0 ? (
                <Box
                    style={{
                        backgroundColor: theme.colors.dark[6],
                        borderRadius: '12px',
                        padding: '40px',
                        textAlign: 'center',
                        border: `1px solid ${theme.colors.dark[4]}`,
                    }}
                >
                    <Group justify="center" mb="md">
                        <IconWifi size={48} color="gray" />
                    </Group>
                    <Text c="dimmed" size="lg" mb="md">No UPNP rules</Text>
                    <Text c="dimmed" size="sm" mb="lg">
                        Add a rule to open ports on your router via UPnP
                    </Text>
                    <Button leftSection={<IconPlus size={16} />} onClick={openCreateModal}>
                        Add Your First Rule
                    </Button>
                </Box>
            ) : (
                <Table
                    striped
                    highlightOnHover
                    withTableBorder
                    style={{
                        backgroundColor: theme.colors.dark[6],
                        borderRadius: '12px',
                        overflow: 'hidden',
                    }}
                >
                    <Table.Thead style={{ backgroundColor: theme.colors.dark[5] }}>
                        <Table.Tr>
                            <Table.Th c="dimmed">Name</Table.Th>
                            <Table.Th c="dimmed">Interface</Table.Th>
                            <Table.Th c="dimmed">External Port</Table.Th>
                            <Table.Th c="dimmed">Internal Port</Table.Th>
                            <Table.Th c="dimmed">Protocol</Table.Th>
                            <Table.Th c="dimmed">Status</Table.Th>
                            <Table.Th c="dimmed">Last Renewed</Table.Th>
                            <Table.Th c="dimmed">Actions</Table.Th>
                        </Table.Tr>
                    </Table.Thead>
                    <Table.Tbody>
                        {rules.map((rule) => (
                            <Table.Tr key={rule.id}>
                                <Table.Td>
                                    <Text fw={500} c="white">{rule.name}</Text>
                                    {rule.description && (
                                        <Text size="xs" c="dimmed">{rule.description}</Text>
                                    )}
                                </Table.Td>
                                <Table.Td>
                                    <Text c="white">{rule.interface}</Text>
                                    {rule.internal_ip && (
                                        <Text size="xs" c="dimmed">{rule.internal_ip}</Text>
                                    )}
                                </Table.Td>
                                <Table.Td>
                                    <Text c="white">{rule.external_port}</Text>
                                </Table.Td>
                                <Table.Td>
                                    <Text c="white">{rule.internal_port}</Text>
                                </Table.Td>
                                <Table.Td>
                                    <Badge
                                        color={rule.protocol === 'TCP' ? 'blue' : 'orange'}
                                        variant="light"
                                    >
                                        {rule.protocol}
                                    </Badge>
                                </Table.Td>
                                <Table.Td>
                                    <Badge
                                        color={rule.is_enabled ? 'green' : 'gray'}
                                        variant="light"
                                    >
                                        {rule.is_enabled ? 'Active' : 'Disabled'}
                                    </Badge>
                                </Table.Td>
                                <Table.Td>
                                    {rule.last_renewed_at ? (
                                        <Text size="sm" c="dimmed">
                                            {new Date(rule.last_renewed_at).toLocaleString()}
                                        </Text>
                                    ) : (
                                        <Text size="sm" c="dimmed">Never</Text>
                                    )}
                                </Table.Td>
                                <Table.Td>
                                    <Group gap="xs">
                                        <ActionIcon
                                            variant="subtle"
                                            color="gray"
                                            onClick={() => openEditModal(rule)}
                                        >
                                            <IconEdit size={16} />
                                        </ActionIcon>
                                        <ActionIcon
                                            variant="subtle"
                                            color="red"
                                            onClick={() => setDeleteConfirm(rule.id)}
                                        >
                                            <IconTrash size={16} />
                                        </ActionIcon>
                                    </Group>
                                </Table.Td>
                            </Table.Tr>
                        ))}
                    </Table.Tbody>
                </Table>
            )}

            <Modal
                opened={opened}
                onClose={closeModal}
                title={<Text fw={600}>{editingRule ? 'Edit UPNP Rule' : 'Add UPNP Rule'}</Text>}
                size="md"
                centered
            >
                <form onSubmit={(e) => { e.preventDefault(); handleSubmit(e); }}>
                    <Stack gap="md">
                        {modalError && (
                            <Alert color="red" variant="light">
                                {modalError}
                            </Alert>
                        )}

                        <TextInput
                            label="Name"
                            placeholder="Web Server"
                            value={formData.name}
                            onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                            required
                        />

                        <Select
                            label="Network Interface"
                            placeholder="Select interface"
                            value={formData.interface}
                            onChange={(value) => setFormData({ ...formData, interface: value })}
                            data={interfaceOptions}
                            required
                            searchable
                        />

                        <Group grow>
                            <TextInput
                                label="External Port"
                                placeholder="8080"
                                value={formData.external_port}
                                onChange={(e) => setFormData({ ...formData, external_port: e.target.value })}
                                required
                            />

                            <TextInput
                                label="Internal Port"
                                placeholder="80"
                                value={formData.internal_port}
                                onChange={(e) => setFormData({ ...formData, internal_port: e.target.value })}
                                required
                            />
                        </Group>

                        <Select
                            label="Protocol"
                            value={formData.protocol}
                            onChange={(value) => setFormData({ ...formData, protocol: value })}
                            data={[
                                { value: 'TCP', label: 'TCP' },
                                { value: 'UDP', label: 'UDP' },
                            ]}
                            required
                        />

                        <TextInput
                            label="Description"
                            placeholder="Optional description"
                            value={formData.description}
                            onChange={(e) => setFormData({ ...formData, description: e.target.value })}
                        />

                        <Switch
                            label="Enabled"
                            checked={formData.is_enabled}
                            onChange={(e) => setFormData({ ...formData, is_enabled: e.target.checked })}
                        />

                        <Group justify="flex-end" mt="md">
                            <Button variant="subtle" onClick={closeModal}>
                                Cancel
                            </Button>
                            <Button type="submit" loading={submitting}>
                                {editingRule ? 'Save Changes' : 'Create'}
                            </Button>
                        </Group>
                    </Stack>
                </form>
            </Modal>

            {/* Delete Confirmation Modal */}
            <Modal
                opened={!!deleteConfirm}
                onClose={() => setDeleteConfirm(null)}
                title={<Text fw={600}>Delete Rule</Text>}
                size="sm"
                centered
            >
                <Text c="dimmed" mb="lg">
                    Are you sure you want to delete this UPNP rule? This will also remove the port mapping from your router.
                </Text>
                <Group justify="flex-end">
                    <Button variant="subtle" onClick={() => setDeleteConfirm(null)}>
                        Cancel
                    </Button>
                    <Button color="red" onClick={() => handleDelete(deleteConfirm)}>
                        Delete
                    </Button>
                </Group>
            </Modal>
        </Box>
    );
}
