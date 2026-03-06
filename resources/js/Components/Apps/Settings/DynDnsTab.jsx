import { useState, useEffect } from 'react';
import {
    Box,
    Title,
    Text,
    Group,
    Button,
    Modal,
    TextInput,
    Stack,
    Switch,
    Badge,
    Loader,
    Alert,
    ActionIcon,
    Menu,
    useMantineTheme,
} from '@mantine/core';
import { useDisclosure } from '@mantine/hooks';
import {
    IconPlus,
    IconDots,
    IconTrash,
    IconEdit,
    IconCloud,
    IconInfoCircle,
    IconCopy,
    IconCheck,
} from '@tabler/icons-react';

export function DynDnsTab() {
    const theme = useMantineTheme();
    const [configs, setConfigs] = useState([]);
    const [providers, setProviders] = useState([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [opened, { open: openModal, close: closeModal }] = useDisclosure(false);
    const [editingConfig, setEditingConfig] = useState(null);
    const [deleteConfirm, setDeleteConfirm] = useState(null);

    const [formData, setFormData] = useState({
        provider: 'novanas',
        name: '',
        subdomain: '',
        token: '',
        interval_minutes: 5,
        is_enabled: true,
    });

    const [modalError, setModalError] = useState(null);
    const [dynDnsInfo, setDynDnsInfo] = useState({ max_subdomains: 0, domain: '' });
    const [subdomainError, setSubdomainError] = useState(null);
    const [copiedId, setCopiedId] = useState(null);

    useEffect(() => {
        fetchConfigs();
        fetchDynDnsInfo();
    }, []);

    const fetchDynDnsInfo = async () => {
        try {
            const response = await fetch('/api/dyndns/info');
            const data = await response.json();
            setDynDnsInfo(data || { max_subdomains: 0, domain: '' });
        } catch (err) {
            console.error('Failed to load DynDNS info:', err);
        }
    };

    const fetchConfigs = async () => {
        try {
            setLoading(true);
            const response = await fetch('/api/dyndns/configs');
            const data = await response.json();

            setConfigs(data.configs || []);
            setProviders(data.available_providers || []);
            setError(null);
        } catch (err) {
            setError('Failed to load DynDNS configurations');
            console.error(err);
        } finally {
            setLoading(false);
        }
    };

    const handleSubmit = async (e) => {
        e.preventDefault();

        // Validate subdomain before submitting
        const subdomainValidation = validateSubdomain(formData.subdomain);
        if (subdomainValidation) {
            setSubdomainError(subdomainValidation);
            return;
        }

        setModalError(null);

        try {
            // Always use NovaNAS provider and fixed 5-minute interval
            const submitData = {
                ...formData,
                provider: 'novanas',
                interval_minutes: 5,
            };

            let url = '/api/dyndns/configs';
            const method = editingConfig ? 'PUT' : 'POST';

            if (editingConfig) {
                url = '/api/dyndns/configs/' + editingConfig.id;
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
                // Handle specific error codes from NovaNAS API
                if (response.status === 403) {
                    throw new Error('Maximum number of DNS records reached for this IP address. Please try again later or contact support.');
                }
                if (response.status === 409) {
                    throw new Error('This subdomain already exists in DNS. Please choose a different subdomain.');
                }
                // For 500 and other errors, try to get the error message from response
                const errorMessage = data.message || data.error || 'Failed to register DNS record.';
                throw new Error(errorMessage);
            }

            await fetchConfigs();
            closeModal();
            resetForm();
        } catch (err) {
            setModalError(err.message);
        }
    };

    const handleDelete = async (id) => {
        try {
            const response = await fetch(`/api/dyndns/configs/${id}`, {
                method: 'DELETE',
            });

            if (!response.ok) {
                const data = await response.json();
                throw new Error(data.message || 'Delete failed');
            }

            await fetchConfigs();
            setDeleteConfirm(null);
        } catch (err) {
            setError(err.message);
        }
    };

    const openEditModal = (config) => {
        setEditingConfig(config);
        setModalError(null);
        setSubdomainError(null);
        setFormData({
            provider: config.provider,
            name: config.name,
            subdomain: config.subdomain,
            token: '',
            interval_minutes: 5,
            is_enabled: config.is_enabled,
        });
        openModal();
    };

    const resetForm = () => {
        setEditingConfig(null);
        setModalError(null);
        setSubdomainError(null);
        setFormData({
            provider: 'novanas',
            name: '',
            subdomain: '',
            token: '',
            interval_minutes: 5,
            is_enabled: true,
        });
    };

    const handleCopyDomain = (config) => {
        const domain = config.full_domain;

        // Try modern clipboard API first
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(domain).then(() => {
                setCopiedId(config.id);
                setTimeout(() => setCopiedId(null), 2000);
            }).catch(() => {
                // Fallback to textarea method
                fallbackCopy(domain, config.id);
            });
        } else {
            // Fallback for older browsers or non-secure contexts
            fallbackCopy(domain, config.id);
        }
    };

    const fallbackCopy = (text, id) => {
        const textarea = document.createElement('textarea');
        textarea.value = text;
        textarea.style.position = 'fixed';
        textarea.style.opacity = '0';
        document.body.appendChild(textarea);
        textarea.select();

        try {
            document.execCommand('copy');
            setCopiedId(id);
            setTimeout(() => setCopiedId(null), 2000);
        } catch (err) {
            console.error('Copy failed:', err);
        }

        document.body.removeChild(textarea);
    };

    const openCreateModal = () => {
        resetForm();
        openModal();
    };

    const providerOptions = providers.map((p) => ({
        value: p.key,
        label: p.name,
    }));

    // Validate subdomain according to DNS naming rules (matching backend SubdomainRule)
    const validateSubdomain = (value) => {
        if (!value) {
            return 'Subdomain is required';
        }
        if (value.length > 63) {
            return 'Subdomain may not be greater than 63 characters.';
        }
        // Only lowercase letters, numbers, hyphens; no leading/trailing hyphens; min 1 char
        if (!/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/.test(value)) {
            return 'Subdomain may only contain lowercase letters, numbers, and hyphens, and must not start or end with a hyphen.';
        }
        // Block consecutive hyphens in positions 3 and 4 (RFC 5891)
        if (value.length >= 4 && value.substring(2, 4) === '--') {
            return 'Subdomain must not have hyphens in both the 3rd and 4th position.';
        }
        // Block all-numeric labels
        if (/^[0-9]+$/.test(value)) {
            return 'Subdomain must not be entirely numeric.';
        }
        return null;
    };

    const handleSubdomainChange = (e) => {
        const value = e.target.value;
        setFormData({ ...formData, subdomain: value });
        setSubdomainError(validateSubdomain(value));
    };

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
                    <Title order={3} c="white">DynDNS</Title>
                    <Text size="sm" c="dimmed">Manage your dynamic DNS configurations</Text>
                </div>
                <Button
                    leftSection={<IconPlus size={16} />}
                    onClick={openCreateModal}
                >
                    Add Configuration
                </Button>
            </Group>

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

            {configs.length === 0 ? (
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
                        <IconCloud size={48} color="gray" />
                    </Group>
                    <Text c="dimmed" size="lg" mb="md">No DynDNS configurations</Text>
                    <Text c="dimmed" size="sm" mb="lg">
                        Add a configuration to keep your dynamic DNS updated
                    </Text>
                    <Button leftSection={<IconPlus size={16} />} onClick={openCreateModal}>
                        Add Your First Configuration
                    </Button>
                </Box>
            ) : (
                <>
                    <Stack gap="md">
                    {configs.map((config) => (
                        <Box
                            key={config.id}
                            style={{
                                backgroundColor: theme.colors.dark[6],
                                borderRadius: '12px',
                                padding: '20px',
                                border: `1px solid ${theme.colors.dark[4]}`,
                            }}
                        >
                            <Group justify="space-between" wrap="nowrap">
                                <Group gap="md" wrap="nowrap">
                                    <Box
                                        style={{
                                            width: '48px',
                                            height: '48px',
                                            borderRadius: '12px',
                                            backgroundColor: theme.colors.blue[6],
                                            display: 'flex',
                                            alignItems: 'center',
                                            justifyContent: 'center',
                                        }}
                                    >
                                        <IconCloud size={24} color="white" />
                                    </Box>
                                    <div>
                                        <Group gap="sm">
                                            <Text fw={600} size="lg" c="white">{config.name}</Text>
                                            <Badge
                                                color={config.is_enabled ? 'green' : 'gray'}
                                                variant="light"
                                                size="sm"
                                            >
                                                {config.is_enabled ? 'Enabled' : 'Disabled'}
                                            </Badge>
                                        </Group>
                                        <Group gap="xs">
                                            <Box
                                                style={{
                                                    display: 'inline-flex',
                                                    alignItems: 'center',
                                                    gap: '8px',
                                                    padding: '6px 12px',
                                                    borderRadius: '8px',
                                                    background: `linear-gradient(135deg, ${theme.colors.blue[7]} 0%, ${theme.colors.blue[5]} 100%)`,
                                                    boxShadow: `0 2px 8px ${theme.colors.blue[8]}40`,
                                                    marginTop: '4px',
                                                    marginBottom: '4px',
                                                }}
                                            >
                                                <Text size="sm" fw={600} c="white" style={{ letterSpacing: '0.3px' }}>
                                                    {config.full_domain}
                                                </Text>
                                                <ActionIcon
                                                    variant="white"
                                                    color="blue"
                                                    size="xs"
                                                    radius="xl"
                                                    onClick={() => handleCopyDomain(config)}
                                                    title="Copy domain"
                                                    style={{ opacity: 0.9 }}
                                                >
                                                    {copiedId === config.id ? <IconCheck size={12} /> : <IconCopy size={12} />}
                                                </ActionIcon>
                                            </Box>
                                        </Group>
                                        <Text size="xs" c="dimmed">
                                            {config.provider_display_name} • Updates every {config.interval_minutes} minute{config.interval_minutes !== 1 ? 's' : ''}
                                            {config.last_updated_at && (
                                                <> • Last updated: {new Date(config.last_updated_at).toLocaleString()}</>
                                            )}
                                        </Text>
                                    </div>
                                </Group>

                                <Group gap="sm" wrap="nowrap">
                                    <Menu shadow="md" width={150} position="bottom-end">
                                        <Menu.Target>
                                            <ActionIcon variant="subtle" color="gray" size="lg">
                                                <IconDots size={18} />
                                            </ActionIcon>
                                        </Menu.Target>

                                        <Menu.Dropdown>
                                            <Menu.Item
                                                leftSection={<IconEdit size={14} />}
                                                onClick={() => openEditModal(config)}
                                            >
                                                Edit
                                            </Menu.Item>
                                            <Menu.Item
                                                color="red"
                                                leftSection={<IconTrash size={14} />}
                                                onClick={() => setDeleteConfirm(config.id)}
                                            >
                                                Delete
                                            </Menu.Item>
                                        </Menu.Dropdown>
                                    </Menu>
                                </Group>
                            </Group>
                        </Box>
                    ))}
                    </Stack>
                </>
            )}

            <Modal
                opened={opened}
                onClose={closeModal}
                title={<Text fw={600}>{editingConfig ? 'Edit DynDNS Configuration' : 'Add DynDNS Configuration'}</Text>}
                size="md"
                centered
            >
                <form onSubmit={(e) => { e.preventDefault(); handleSubmit(e); }}>
                    <Stack gap="md">
                        {formData.provider === 'novanas' && (
                            <Alert
                                color="blue"
                                variant="light"
                                icon={<IconInfoCircle size={16} />}
                            >
                                <Text fw={500} mb="xs">NovaNAS Cloud DynDNS</Text>
                                <Text size="sm">
                                    Free dynamic DNS service hosted by NovaNAS Cloud.
                                    You can create up to {dynDnsInfo.max_subdomains} subdomain{dynDnsInfo.max_subdomains !== 1 ? 's' : ''} per IP address.
                                </Text>
                            </Alert>
                        )}

                        {modalError && (
                            <Alert color="red" variant="light">
                                {modalError}
                            </Alert>
                        )}

                        <TextInput
                            label="Name"
                            placeholder="My NovaNAS DDNS"
                            value={formData.name}
                            onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                            required
                        />

                        <TextInput
                            label="Subdomain"
                            placeholder="yourdomain"
                            value={formData.subdomain}
                            onChange={handleSubdomainChange}
                            error={subdomainError}
                            required
                            rightSection={
                                <Group gap={0} wrap="nowrap">
                                    <Box
                                        style={{
                                            width: '1px',
                                            height: '20px',
                                            backgroundColor: theme.colors.dark[4],
                                            marginRight: '8px',
                                        }}
                                    />
                                    <Text
                                        size="sm"
                                        fw={600}
                                        c={theme.primaryColor}
                                        style={{ whiteSpace: 'nowrap' }}
                                    >
                                        .{formData.provider === 'novanas' ? dynDnsInfo.domain : 'duckdns.org'}
                                    </Text>
                                </Group>
                            }
                            rightSectionWidth={130}
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
                            <Button type="submit">
                                {editingConfig ? 'Save Changes' : 'Create'}
                            </Button>
                        </Group>
                    </Stack>
                </form>
            </Modal>

            {/* Delete Confirmation Modal */}
            <Modal
                opened={!!deleteConfirm}
                onClose={() => setDeleteConfirm(null)}
                title={<Text fw={600}>Delete Configuration</Text>}
                size="sm"
                centered
            >
                <Text c="dimmed" mb="lg">
                    Are you sure you want to delete this DynDNS configuration? This action cannot be undone.
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
