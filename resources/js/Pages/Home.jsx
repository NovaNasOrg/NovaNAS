import { DesktopLayout } from '@/Components/Desktop/DesktopLayout';

export default function Home({ version, desktopApps, userIconOrders }) {
    return <DesktopLayout version={version} desktopApps={desktopApps} userIconOrders={userIconOrders} />;
}
