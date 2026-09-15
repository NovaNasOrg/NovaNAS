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
    Switch,
    Textarea,
    Stack,
    Badge,
    Loader,
    Alert,
    ActionIcon,
    Table,
    Tooltip,
    useMantineTheme,
} from '@mantine/core';
import { useDisclosure } from '@mantine/hooks';
import {
    IconPlus,
    IconTrash,
    IconEdit,
    IconRefresh,
    IconCheck,
    IconAlertTriangle,
    IconWorld,
    IconLock,
    IconTransfer,
} from '@tabler/icons-react';
import { useIsMobile } from '../Desktop/useIsMobile';
import { useConfirmModal } from '../ConfirmModal';

const SSL_MODE_LABELS = {
    none: 'None (HTTP only)',
    letsencrypt: "Let's Encrypt",
    selfsigned: 'Self-Signed',
    custom: 'Custom',
};

function ReachabilityAlert({ reachability, domain }) {
    if (!reachability) {
        return null;
    }

    return (
        <Alert
            color={reachability.reachable ? 'green' : 'yellow'}
            variant="light"
            icon={reachability.reachable ? <IconCheck size={16} /> : <IconAlertTriangle size={16} />}
        >
            <Text fw={500}>
                {reachability.reachable
                    ? `Reachable${reachability.ip ? ` (IP: ${reachability.ip})` : ''}`
                    : reachability.ip === null
                        ? 'Domain cannot be resolved'
                        : 'Not reachable from the internet'}
            </Text>
            {!reachability.reachable && reachability.ip === null && (
                <Text size="sm" mt={4}>
                    The domain <strong>{domain}</strong> cannot be resolved by the public DNS server. Verify that its
                    DNS records (e.g. an A record pointing to your public IP) are properly configured, then re-run the check.
                </Text>
            )}
            {!reachability.reachable && reachability.ip !== null && (
                <Text size="sm" mt={4}>
                    Let&apos;s Encrypt certificates will not be available. You can use a custom certificate instead.
                </Text>
            )}
            {reachability.reachable && reachability.message && (
                <Text size="sm" mt={4}>{reachability.message}</Text>
            )}
        </Alert>
    );
}

export function ReverseProxyAppContent() {
    const theme = useMantineTheme();
    const isMobile = useIsMobile();
    const [hosts, setHosts] = useState([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [success, setSuccess] = useState(null);
    const [refreshing, setRefreshing] = useState(false);
    const [togglingId, setTogglingId] = useState(null);
    const [opened, { open: openModal, close: closeModal }] = useDisclosure(false);
    const [editingHost, setEditingHost] = useState(null);
    const [submitting, setSubmitting] = useState(false);
    const [modalError, setModalError] = useState(null);
    const [reachability, setReachability] = useState(null);
    const [checkingReachability, setCheckingReachability] = useState(false);
    const [certBusy, setCertBusy] = useState(false);
    const [confirmDeleteHost, deleteHostConfirmModal] = useConfirmModal();
    const [confirmRemoveCert, removeCertConfirmModal] = useConfirmModal();

    const [formData, setFormData] = useState({
        domain: '',
        target_protocol: 'http',
        target_host: '',
        target_port: '',
        websocket_enabled: false,
        https_redirect: false,
        enabled: true,
        ssl_mode: 'none',
    });

    const [customCert, setCustomCert] = useState({
        certificate: '',
        private_key: '',
        ca_bundle: '',
    });

    useEffect(() => {
        fetchHosts();
    }, []);

    const fetchHosts = async () => {
        setLoading(true);

        try {
            const response = await fetch('/api/reverse-proxy/hosts');
            const data = await response.json();
            setHosts(data.hosts || []);
            setError(null);

            return data.hosts || [];
        } catch (err) {
            setError('Failed to load proxy hosts');
            console.error(err);

            return [];
        } finally {
            setLoading(false);
        }
    };

    const handleRefresh = async () => {
        setRefreshing(true);
        try {
            await fetchHosts();
        } finally {
            setRefreshing(false);
        }
    };

    const resetForm = () => {
        setEditingHost(null);
        setModalError(null);
        setReachability(null);
        setCustomCert({ certificate: '', private_key: '', ca_bundle: '' });
        setFormData({
            domain: '',
            target_protocol: 'http',
            target_host: '',
            target_port: '',
            websocket_enabled: false,
            https_redirect: false,
            enabled: true,
            ssl_mode: 'none',
        });
    };

    const openCreateModal = () => {
        resetForm();
        openModal();
    };

    const openEditModal = (host) => {
        setEditingHost(host);
        setModalError(null);
        setReachability(null);
        setCustomCert({ certificate: '', private_key: '', ca_bundle: '' });
        setFormData({
            domain: host.domain || '',
            target_protocol: host.target_protocol || 'http',
            target_host: host.target_host || '',
            target_port: host.target_port || '',
            websocket_enabled: !!host.websocket_enabled,
            https_redirect: !!host.https_redirect,
            enabled: host.enabled !== false,
            ssl_mode: host.ssl_mode || 'none',
        });
        openModal();
    };

    const handleSubmit = async (e) => {
        e.preventDefault();
        setModalError(null);
        setSubmitting(true);

        try {
            const submitData = {
                domain: formData.domain,
                target_protocol: formData.target_protocol,
                target_host: formData.target_host,
                target_port: formData.target_port ? Number(formData.target_port) : null,
                websocket_enabled: formData.websocket_enabled,
                https_redirect: formData.https_redirect,
                enabled: formData.enabled,
            };

            const response = editingHost
                ? await fetch(`/api/reverse-proxy/hosts/${editingHost.id}`, {
                    method: 'PUT',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(submitData),
                })
                : await fetch('/api/reverse-proxy/hosts', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(submitData),
                });

            const data = await response.json();

            if (!response.ok) {
                throw new Error(data.message || 'Failed to save proxy host');
            }

            await fetchHosts();

            if (!editingHost && data.host) {
                // Continue editing the freshly created host so certificates can be requested
                setEditingHost(data.host);
                setFormData((prev) => ({ ...prev, ssl_mode: 'none' }));
            } else {
                closeModal();
                resetForm();
            }

            setSuccess(data.message || 'Proxy host saved.');
        } catch (err) {
            setModalError(err.message);
        } finally {
            setSubmitting(false);
        }
    };

    const handleToggle = async (host, checked) => {
        setTogglingId(host.id);
        setError(null);

        try {
            const response = await fetch(`/api/reverse-proxy/hosts/${host.id}/toggle`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ enabled: checked }),
            });
            const data = await response.json();

            if (!response.ok) {
                throw new Error(data.message || 'Failed to toggle proxy host');
            }

            await fetchHosts();
        } catch (err) {
            setError(err.message);
        } finally {
            setTogglingId(null);
        }
    };

    const handleDeleteHost = async (host) => {
        const confirmed = await confirmDeleteHost({
            title: 'Delete Proxy Host',
            message: `Are you sure you want to delete the proxy host "${host.domain}"? Its certificate and Apache configuration will be removed. This action cannot be undone.`,
            confirmLabel: 'Delete',
        });

        if (!confirmed) {
            return;
        }

        setError(null);

        try {
            const response = await fetch(`/api/reverse-proxy/hosts/${host.id}`, {
                method: 'DELETE',
            });
            const data = await response.json();

            if (!response.ok) {
                throw new Error(data.message || 'Failed to delete proxy host');
            }

            setSuccess(data.message || 'Proxy host deleted.');
            await fetchHosts();
        } catch (err) {
            setError(err.message);
        }
    };

    const handleCheckReachability = async () => {
        setCheckingReachability(true);
        setReachability(null);
        setModalError(null);

        try {
            const response = await fetch('/api/reverse-proxy/check-reachability', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ domain: formData.domain }),
            });
            const data = await response.json();

            if (!response.ok) {
                throw new Error(data.message || 'Failed to check reachability');
            }

            setReachability(data);
        } catch (err) {
            setModalError(err.message);
        } finally {
            setCheckingReachability(false);
        }
    };

    const syncEditingHost = async () => {
        try {
            const response = await fetch('/api/reverse-proxy/hosts');
            const data = await response.json();

            if (!response.ok) {
                throw new Error(data.message || 'Failed to load proxy hosts');
            }

            const updatedHosts = data.hosts || [];
            setHosts(updatedHosts);
            setError(null);

            if (editingHost) {
                const updated = updatedHosts.find((h) => h.id === editingHost.id);

                if (updated) {
                    setEditingHost(updated);
                }
            }
        } catch (err) {
            setModalError(err.message || 'Failed to load proxy hosts');
        }
    };

    const handleIssueLetsEncrypt = async () => {
        if (!editingHost) {
            return;
        }

        setCertBusy(true);
        setModalError(null);

        try {
            const response = await fetch(`/api/reverse-proxy/hosts/${editingHost.id}/ssl/letsencrypt`, {
                method: 'POST',
            });
            const data = await response.json();

            if (!response.ok) {
                throw new Error(data.message || 'Failed to request certificate');
            }

            setSuccess(data.message || 'Certificate issued and installed.');
            await syncEditingHost();
        } catch (err) {
            setModalError(err.message);
        } finally {
            setCertBusy(false);
        }
    };

    const handleGenerateSelfSigned = async () => {
        if (!editingHost) {
            return;
        }

        setCertBusy(true);
        setModalError(null);

        try {
            const response = await fetch(`/api/reverse-proxy/hosts/${editingHost.id}/ssl/self-signed`, {
                method: 'POST',
            });
            const data = await response.json();

            if (!response.ok) {
                throw new Error(data.message || 'Failed to generate self-signed certificate');
            }

            setSuccess(data.message || 'Self-signed certificate generated.');
            await syncEditingHost();
        } catch (err) {
            setModalError(err.message);
        } finally {
            setCertBusy(false);
        }
    };

    const handleInstallCustomCertificate = async () => {
        if (!editingHost) {
            return;
        }

        setCertBusy(true);
        setModalError(null);

        try {
            const response = await fetch(`/api/reverse-proxy/hosts/${editingHost.id}/ssl/custom`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(customCert),
            });
            const data = await response.json();

            if (!response.ok) {
                throw new Error(data.message || 'Failed to install certificate');
            }

            setSuccess(data.message || 'Certificate installed.');
            setCustomCert({ certificate: '', private_key: '', ca_bundle: '' });
            await syncEditingHost();
        } catch (err) {
            setModalError(err.message);
        } finally {
            setCertBusy(false);
        }
    };

    const handleRemoveCertificate = async () => {
        if (!editingHost) {
            return;
        }

        const confirmed = await confirmRemoveCert({
            title: 'Remove Certificate',
            message: `Remove the certificate for "${editingHost.domain}"? The host will fall back to plain HTTP proxying and the HTTPS redirect will be turned off.`,
            confirmLabel: 'Remove',
            color: 'red',
        });

        if (!confirmed) {
            return;
        }

        setCertBusy(true);
        setModalError(null);

        try {
            const response = await fetch(`/api/reverse-proxy/hosts/${editingHost.id}/ssl`, {
                method: 'DELETE',
            });
            const data = await response.json();

            if (!response.ok) {
                throw new Error(data.message || 'Failed to remove certificate');
            }

            setSuccess(data.message || 'Certificate removed.');
            setFormData((prev) => ({ ...prev, https_redirect: false, ssl_mode: 'none' }));
            await syncEditingHost();
        } catch (err) {
            setModalError(err.message);
        } finally {
            setCertBusy(false);
        }
    };

    const certActive = editingHost?.certificate?.exists === true;
    const isSavedHost = !!editingHost;
    const savedHint = !isSavedHost
        ? 'Save the proxy host first to be able to request or install a certificate.'
        : null;

    const sslModeOptions = Object.entries(SSL_MODE_LABELS).map(([value, label]) => ({ value, label }));

    const renderSslBadge = (host) => {
        if (host.ssl_mode === 'none' || !host.certificate?.exists) {
            return (
                <Badge color="gray" variant="light">
                    No SSL
                </Badge>
            );
        }

        return (
            <Tooltip label={host.certificate.expires_at ? `Expires: ${host.certificate.expires_at}` : undefined} disabled={!host.certificate.expires_at}>
                <Badge color="green" variant="light">
                    {SSL_MODE_LABELS[host.ssl_mode] || host.ssl_mode}
                </Badge>
            </Tooltip>
        );
    };

    if (loading) {
        return (
            <Box style={{ display: 'flex', justifyContent: 'center', alignItems: 'center', height: '100%' }}>
                <Loader size="lg" />
            </Box>
        );
    }

    return (
        <Box style={{ padding: isMobile ? '12px' : '24px', height: '100%', overflow: 'auto' }}>
            <Group justify="space-between" mb="lg" wrap={isMobile ? 'wrap' : 'nowrap'}>
                <div>
                    <Title order={isMobile ? 4 : 3} c="white">Reverse Proxy</Title>
                    <Text size="sm" c="dimmed">Proxy public domains to internal services via Apache</Text>
                </div>
                <Group gap="sm" wrap={isMobile ? 'wrap' : 'nowrap'}>
                    <Button
                        variant="light"
                        color="blue"
                        leftSection={<IconRefresh size={18} />}
                        onClick={handleRefresh}
                        loading={refreshing}
                        size={isMobile ? 'compact-sm' : 'md'}
                    >
                        Refresh
                    </Button>
                    <Button
                        leftSection={<IconPlus size={16} />}
                        onClick={openCreateModal}
                        size={isMobile ? 'compact-sm' : 'md'}
                    >
                        Add Host
                    </Button>
                </Group>
            </Group>

            {error && (
                <Alert
                    color="red"
                    variant="light"
                    mb="md"
                    onClose={() => setError(null)}
                    withCloseButton
                    icon={<IconAlertTriangle size={16} />}
                >
                    {error}
                </Alert>
            )}

            {success && (
                <Alert
                    color="green"
                    variant="light"
                    mb="md"
                    onClose={() => setSuccess(null)}
                    withCloseButton
                    icon={<IconCheck size={16} />}
                >
                    {success}
                </Alert>
            )}

            <Title order={4} mb="md">Proxy Hosts</Title>

            {hosts.length === 0 ? (
                <Box
                    style={{
                        backgroundColor: theme.colors.dark[6],
                        borderRadius: '12px',
                        padding: isMobile ? '24px' : '40px',
                        textAlign: 'center',
                        border: `1px solid ${theme.colors.dark[4]}`,
                    }}
                >
                    <Group justify="center" mb="md">
                        <IconTransfer size={isMobile ? 36 : 48} color="gray" />
                    </Group>
                    <Text c="dimmed" size={isMobile ? 'sm' : 'lg'} mb="md">No proxy hosts</Text>
                    <Text c="dimmed" size="sm" mb="lg">
                        Add a host to make an internal service available under your own domain
                    </Text>
                    <Button leftSection={<IconPlus size={16} />} onClick={openCreateModal} size={isMobile ? 'compact-sm' : 'md'}>
                        Add Your First Host
                    </Button>
                </Box>
            ) : isMobile ? (
                // Mobile: card layout
                <Stack gap="sm">
                    {hosts.map((host) => (
                        <Box
                            key={host.id}
                            style={{
                                backgroundColor: theme.colors.dark[6],
                                borderRadius: '12px',
                                padding: '14px',
                                border: `1px solid ${theme.colors.dark[4]}`,
                            }}
                        >
                            <Group justify="space-between" mb="xs">
                                <Text size="sm" c="white" fw={500}>{host.domain}</Text>
                                <Group gap="xs">
                                    <ActionIcon
                                        variant="subtle"
                                        color="gray"
                                        size="sm"
                                        onClick={() => openEditModal(host)}
                                    >
                                        <IconEdit size={14} />
                                    </ActionIcon>
                                    <ActionIcon
                                        variant="subtle"
                                        color="red"
                                        size="sm"
                                        onClick={() => handleDeleteHost(host)}
                                    >
                                        <IconTrash size={14} />
                                    </ActionIcon>
                                </Group>
                            </Group>
                            <Group gap="xs" mb="xs" wrap="wrap">
                                {renderSslBadge(host)}
                                {host.websocket_enabled && (
                                    <Badge color="blue" variant="light" size="sm">
                                        WebSocket
                                    </Badge>
                                )}
                                {host.https_redirect && (
                                    <Badge color="teal" variant="light" size="sm">
                                        HTTPS Redirect
                                    </Badge>
                                )}
                            </Group>
                            <Group justify="space-between" align="center">
                                <Box>
                                    <Text size="xs" c="dimmed">Target</Text>
                                    <Text size="sm" c="white">{host.target_protocol}://{host.target_host}:{host.target_port}</Text>
                                </Box>
                                <Switch
                                    checked={host.enabled}
                                    onChange={(e) => handleToggle(host, e.currentTarget.checked)}
                                    disabled={togglingId === host.id}
                                    size="sm"
                                    label="Enabled"
                                />
                            </Group>
                        </Box>
                    ))}
                </Stack>
            ) : (
                // Desktop: table layout
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
                            <Table.Th c="dimmed">Domain</Table.Th>
                            <Table.Th c="dimmed">Target</Table.Th>
                            <Table.Th c="dimmed">WebSocket</Table.Th>
                            <Table.Th c="dimmed">SSL</Table.Th>
                            <Table.Th c="dimmed">HTTPS Redirect</Table.Th>
                            <Table.Th c="dimmed">Enabled</Table.Th>
                            <Table.Th c="dimmed">Actions</Table.Th>
                        </Table.Tr>
                    </Table.Thead>
                    <Table.Tbody>
                        {hosts.map((host) => (
                            <Table.Tr key={host.id}>
                                <Table.Td>
                                    <Text c="white">{host.domain}</Text>
                                </Table.Td>
                                <Table.Td>
                                    <Text c="white">{host.target_protocol}://{host.target_host}:{host.target_port}</Text>
                                </Table.Td>
                                <Table.Td>
                                    {host.websocket_enabled ? (
                                        <Badge color="blue" variant="light">WebSocket</Badge>
                                    ) : (
                                        <Text c="dimmed">-</Text>
                                    )}
                                </Table.Td>
                                <Table.Td>{renderSslBadge(host)}</Table.Td>
                                <Table.Td>
                                    {host.https_redirect ? (
                                        <Badge color="teal" variant="light">Redirect</Badge>
                                    ) : (
                                        <Text c="dimmed">-</Text>
                                    )}
                                </Table.Td>
                                <Table.Td>
                                    <Switch
                                        checked={host.enabled}
                                        onChange={(e) => handleToggle(host, e.currentTarget.checked)}
                                        disabled={togglingId === host.id}
                                        size="sm"
                                    />
                                </Table.Td>
                                <Table.Td>
                                    <Group gap="xs">
                                        <Tooltip label="Edit host">
                                            <ActionIcon
                                                variant="subtle"
                                                color="gray"
                                                onClick={() => openEditModal(host)}
                                            >
                                                <IconEdit size={16} />
                                            </ActionIcon>
                                        </Tooltip>
                                        <Tooltip label="Delete host">
                                            <ActionIcon
                                                variant="subtle"
                                                color="red"
                                                onClick={() => handleDeleteHost(host)}
                                            >
                                                <IconTrash size={16} />
                                            </ActionIcon>
                                        </Tooltip>
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
                title={<Text fw={600}>{editingHost ? 'Edit Proxy Host' : 'Add Proxy Host'}</Text>}
                size={isMobile ? '100%' : '640px'}
                centered
            >
                <form onSubmit={(e) => { e.preventDefault(); handleSubmit(e); }}>
                    <Stack gap="md">
                        {modalError && (
                            <Alert color="red" variant="light" icon={<IconAlertTriangle size={16} />}>
                                {modalError}
                            </Alert>
                        )}

                        <TextInput
                            label="Domain"
                            placeholder="app.example.com"
                            description="Public domain that points to this NAS. Must not be the NAS hostname or a DynDNS domain."
                            value={formData.domain}
                            onChange={(e) => setFormData({ ...formData, domain: e.target.value })}
                            required
                        />

                        <Group grow>
                            <Select
                                label="Scheme"
                                value={formData.target_protocol}
                                onChange={(value) => setFormData({ ...formData, target_protocol: value })}
                                data={[
                                    { value: 'http', label: 'http' },
                                    { value: 'https', label: 'https' },
                                ]}
                                required
                            />
                            <TextInput
                                label="Forward Host"
                                placeholder="192.168.1.50 or service.local"
                                value={formData.target_host}
                                onChange={(e) => setFormData({ ...formData, target_host: e.target.value })}
                                required
                            />
                            <TextInput
                                label="Forward Port"
                                placeholder="8096"
                                type="number"
                                min={1}
                                max={65535}
                                value={formData.target_port}
                                onChange={(e) => setFormData({ ...formData, target_port: e.target.value })}
                                required
                            />
                        </Group>

                        <Switch
                            label="WebSocket support"
                            description="Enable websocket proxying (e.g. for apps with live updates)."
                            checked={formData.websocket_enabled}
                            onChange={(e) => setFormData({ ...formData, websocket_enabled: e.currentTarget.checked })}
                        />

                        <Tooltip
                            label="A certificate is required for the HTTPS redirect"
                            disabled={certActive}
                            withArrow
                        >
                            <Box w="fit-content">
                                <Switch
                                    label="Redirect HTTP to HTTPS"
                                    description={certActive
                                        ? 'All HTTP traffic will be redirected to HTTPS.'
                                        : 'Requires an active certificate for this host.'}
                                    checked={formData.https_redirect}
                                    onChange={(e) => setFormData({ ...formData, https_redirect: e.currentTarget.checked })}
                                    disabled={!certActive}
                                />
                            </Box>
                        </Tooltip>

                        <Switch
                            label="Enabled"
                            description="Disabled hosts keep their settings but are removed from Apache."
                            checked={formData.enabled}
                            onChange={(e) => setFormData({ ...formData, enabled: e.currentTarget.checked })}
                        />

                        {/* SSL certificate section */}
                        <Box
                            p="md"
                            style={{
                                backgroundColor: theme.colors.dark[6],
                                borderRadius: '12px',
                                border: `1px solid ${theme.colors.dark[4]}`,
                            }}
                        >
                            <Title order={5} mb="md">SSL Certificate</Title>

                            {certActive && editingHost?.certificate && (
                                <Stack gap="xs" mb="md">
                                    <Text size="sm">
                                        Issuer: <strong c="white">{editingHost.certificate.issuer || 'Unknown'}</strong>
                                    </Text>
                                    {editingHost.certificate.expires_at && (
                                        <Text size="sm">
                                            Expires: <strong c="white">{editingHost.certificate.expires_at}</strong>
                                        </Text>
                                    )}
                                    <Button
                                        color="red"
                                        variant="light"
                                        leftSection={<IconTrash size={16} />}
                                        onClick={handleRemoveCertificate}
                                        loading={certBusy}
                                    >
                                        Remove Certificate
                                    </Button>
                                </Stack>
                            )}

                            <Select
                                label="Certificate mode"
                                value={formData.ssl_mode}
                                onChange={(value) => {
                                    setFormData({ ...formData, ssl_mode: value });
                                    setReachability(null);
                                }}
                                data={sslModeOptions}
                                mb="md"
                            />

                            {formData.ssl_mode === 'letsencrypt' && (
                                <Stack gap="md">
                                    <Text size="sm" c="dimmed">
                                        Request a free certificate from Let&apos;s Encrypt via acme.sh. The domain must
                                        point to this NAS and port 80 must be reachable.
                                    </Text>
                                    {savedHint && (
                                        <Alert color="yellow" variant="light">{savedHint}</Alert>
                                    )}
                                    <Group>
                                        <Button
                                            variant="light"
                                            leftSection={<IconWorld size={16} />}
                                            onClick={handleCheckReachability}
                                            loading={checkingReachability}
                                            disabled={!formData.domain}
                                        >
                                            Check Reachability
                                        </Button>
                                        <Button
                                            leftSection={<IconLock size={16} />}
                                            onClick={handleIssueLetsEncrypt}
                                            loading={certBusy}
                                            disabled={!isSavedHost || certActive}
                                        >
                                            Request Certificate
                                        </Button>
                                    </Group>
                                    <ReachabilityAlert reachability={reachability} domain={formData.domain} />
                                </Stack>
                            )}

                            {formData.ssl_mode === 'selfsigned' && (
                                <Stack gap="md">
                                    <Text size="sm" c="dimmed">
                                        Generate a self-signed certificate valid for 3 months, automatically renewed
                                        monthly. Browsers will show a security warning.
                                    </Text>
                                    {savedHint && (
                                        <Alert color="yellow" variant="light">{savedHint}</Alert>
                                    )}
                                    <Group>
                                        <Button
                                            color="yellow"
                                            leftSection={<IconLock size={16} />}
                                            onClick={handleGenerateSelfSigned}
                                            loading={certBusy}
                                            disabled={!isSavedHost || certActive}
                                        >
                                            Generate Self-Signed Certificate
                                        </Button>
                                    </Group>
                                </Stack>
                            )}

                            {formData.ssl_mode === 'custom' && (
                                <Stack gap="md">
                                    <Text size="sm" c="dimmed">
                                        Paste your own certificate, private key, and optionally a CA bundle in PEM format.
                                    </Text>
                                    {savedHint && (
                                        <Alert color="yellow" variant="light">{savedHint}</Alert>
                                    )}
                                    <Textarea
                                        label="Certificate (PEM)"
                                        placeholder="-----BEGIN CERTIFICATE-----"
                                        value={customCert.certificate}
                                        onChange={(e) => setCustomCert({ ...customCert, certificate: e.target.value })}
                                        minRows={6}
                                        required
                                        styles={{ input: { fontFamily: 'monospace', fontSize: '12px' } }}
                                    />
                                    <Textarea
                                        label="Private Key (PEM)"
                                        placeholder="-----BEGIN PRIVATE KEY-----"
                                        value={customCert.private_key}
                                        onChange={(e) => setCustomCert({ ...customCert, private_key: e.target.value })}
                                        minRows={6}
                                        required
                                        styles={{ input: { fontFamily: 'monospace', fontSize: '12px' } }}
                                    />
                                    <Textarea
                                        label="CA Bundle (optional, PEM)"
                                        placeholder="-----BEGIN CERTIFICATE-----"
                                        value={customCert.ca_bundle}
                                        onChange={(e) => setCustomCert({ ...customCert, ca_bundle: e.target.value })}
                                        minRows={4}
                                        styles={{ input: { fontFamily: 'monospace', fontSize: '12px' } }}
                                    />
                                    <Group>
                                        <Button
                                            leftSection={<IconLock size={16} />}
                                            onClick={handleInstallCustomCertificate}
                                            loading={certBusy}
                                            disabled={!isSavedHost || !customCert.certificate || !customCert.private_key}
                                        >
                                            Save Certificate
                                        </Button>
                                    </Group>
                                </Stack>
                            )}
                        </Box>

                        <Group justify="flex-end" mt="md">
                            <Button variant="subtle" onClick={closeModal}>
                                Close
                            </Button>
                            <Button type="submit" loading={submitting}>
                                {editingHost ? 'Save Changes' : 'Create'}
                            </Button>
                        </Group>
                    </Stack>
                </form>
            </Modal>

            {deleteHostConfirmModal}
            {removeCertConfirmModal}
        </Box>
    );
}
