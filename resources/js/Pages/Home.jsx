import { DesktopLayout } from '@/Components/Desktop/DesktopLayout';

export default function Home({ version, desktopApps, userIconPositions }) {
    return <DesktopLayout version={version} desktopApps={desktopApps} userIconPositions={userIconPositions} />;
}
